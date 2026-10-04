<?php

namespace App\Enums;

/** Delivery channel for client notifications. */
enum NotificationChannelType: string
{
    case Email = 'email';
    case Sms = 'sms';
    case InApp = 'in_app';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::InApp => 'In-app',
        };
    }
}
