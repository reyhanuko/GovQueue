<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An in-app message for the back office - staff and administrators.
 *
 * Separate from AppointmentNotification because these do not describe an
 * appointment of the recipient's own. A client row says "your appointment moved";
 * this says "the lobby display has stopped updating" or "Rosa has been moved to
 * Window 3", and it goes to the people who can act on it.
 *
 * Database channel only, by design:
 *  - these are internal operational facts, not messages the office promised to
 *    deliver to anyone, so they have no place in notification_logs or on the
 *    client delivery screen;
 *  - staff read the screen they are already sitting at, and an email about a
 *    dead socket is not actionable while the socket is dead.
 *
 * The payload is self-contained. The inbox renders from the array alone, so a
 * message about a window that has since been renamed still reads correctly
 * rather than silently changing to today's wording.
 */
class OpsNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $level  info|warning|critical - drives the row's colour.
     * @param  array<string, mixed>  $context  Extra key/value detail shown on
     *                                         the inbox row when present.
     */
    public function __construct(
        public string $title,
        public string $summary,
        public string $url,
        public string $level = 'info',
        public array $context = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ops',
            'title' => $this->title,
            'summary' => $this->summary,
            'url' => $this->url,
            'level' => $this->level,
            'context' => $this->context,
        ];
    }

    /**
     * The inbox reads the summary from the payload, exactly as it does for
     * client messages, so both kinds of row come out of one template.
     *
     * @param  array<string, mixed>  $data
     */
    public static function summaryFor(array $data): string
    {
        return (string) ($data['summary'] ?? 'An update from the office.');
    }
}
