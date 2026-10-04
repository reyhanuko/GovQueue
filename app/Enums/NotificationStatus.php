<?php

namespace App\Enums;

/** Outcome of a notification dispatch attempt. */
enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Queued => 'bg-slate-100 text-slate-700',
            self::Sent => 'bg-emerald-100 text-emerald-700',
            self::Failed => 'bg-rose-100 text-rose-700',
        };
    }
}
