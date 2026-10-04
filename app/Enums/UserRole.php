<?php

namespace App\Enums;

enum UserRole: string
{
    case Client = 'client';
    case Staff = 'staff';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client',
            self::Staff => 'Office Staff',
            self::Admin => 'Administrator',
        };
    }
}
