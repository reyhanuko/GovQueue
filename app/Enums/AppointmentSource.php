<?php

namespace App\Enums;

/** How a client entered the queue. */
enum AppointmentSource: string
{
    case Online = 'online';
    case WalkIn = 'walk_in';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online booking',
            self::WalkIn => 'Walk-in',
        };
    }
}
