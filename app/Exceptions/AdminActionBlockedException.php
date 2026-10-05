<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when an administrator asks for something that would break data that
 * clients or staff already depend on.
 *
 * The schema's foreign keys do the same job at a lower level, but far too late
 * and far too bluntly:
 *
 *  - appointments.service_id is restrictOnDelete, so deleting a service that has
 *    ever been booked raises a raw integrity-constraint error - a 500 with a
 *    stack trace, for what is really a "please deactivate it instead" situation.
 *  - services.service_window_id is cascadeOnDelete, so deleting a window
 *    silently deletes every service in it. If any of those had appointments the
 *    transaction then fails partway, having already removed rows.
 *
 * So the rules are checked in the service layer first, and the FKs stay as the
 * last line of defence rather than the first.
 */
class AdminActionBlockedException extends Exception
{
    public function __construct(string $message, public readonly ?string $hint = null)
    {
        parent::__construct($message);
    }

    public static function windowHasServices(int $count, string $windowName): self
    {
        return new self(
            "Cannot delete {$windowName} because {$count} service(s) are still assigned to it.",
            'Move those services to another window, or deactivate the window instead. '
                .'Deactivating hides it from clients and keeps the history intact.',
        );
    }

    public static function windowHasStaff(int $count, string $windowName): self
    {
        return new self(
            "Cannot delete {$windowName} because {$count} staff account(s) are assigned to it.",
            'Reassign those staff to another window first.',
        );
    }

    public static function serviceHasAppointments(int $count, string $serviceName): self
    {
        return new self(
            "Cannot delete {$serviceName} because {$count} appointment(s) reference it.",
            'Appointments must keep pointing at a real service, so this is enforced by the '
                .'database too. Deactivate the service instead: clients can no longer book it, '
                .'and existing appointments stay valid.',
        );
    }

    public static function serviceStillHasOpenSlots(string $serviceName, int $count): self
    {
        return new self(
            "Cannot delete {$serviceName} because {$count} bookable slot(s) remain.",
            'Close the remaining slots first, so no client books a service that is about to '
                .'disappear from under them.',
        );
    }

    public static function slotHasAppointments(int $count, string $label): self
    {
        return new self(
            "Cannot delete {$label} because {$count} client(s) are booked into it.",
            'Close the slot instead of deleting it, so the booking history stays readable.',
        );
    }

    public static function capacityBelowBooked(int $booked, string $label): self
    {
        return new self(
            "Cannot set {$label} to a capacity below the {$booked} client(s) already booked.",
            'Move or cancel those clients first. A capacity lower than the number booked would '
                .'make the slot permanently over-subscribed and break the remaining count.',
        );
    }

    public static function lastAdmin(string $name): self
    {
        return new self(
            'Cannot remove the last active administrator.',
            "{$name} is the only administrator left. Promote another account first, otherwise "
                .'nobody could reach this configuration area again.',
        );
    }

    public static function cannotChangeYourOwnAccount(string $action): self
    {
        return new self(
            "You cannot {$action} your own account.",
            'Ask another administrator to do it, so the system can never be left without a '
                .'usable administrator.',
        );
    }

    public static function templateRangeInverted(string $label): self
    {
        return new self(
            "Cannot save {$label}: the end time is not after the start time.",
            'A slot template has to describe a real window of the day.',
        );
    }

    public static function templateTooShortForSlot(string $label, int $minutes, int $slotMinutes): self
    {
        return new self(
            "Cannot save {$label}: a {$slotMinutes}-minute slot does not fit in a "
                ."{$minutes}-minute window.",
            'Shorten the slot length or lengthen the window, otherwise no slots would ever be '
                .'generated.',
        );
    }

    public static function effectiveDatesInverted(string $label): self
    {
        return new self(
            "Cannot save {$label}: the effective end date falls before the start date.",
            'Leave the end date empty for an open-ended template.',
        );
    }
}
