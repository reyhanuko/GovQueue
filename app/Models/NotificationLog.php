<?php

namespace App\Models;

use App\Enums\NotificationChannelType;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'user_id',
        'channel',
        'type',
        'destination',
        'subject',
        'status',
        'attempts',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannelType::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markSent(): void
    {
        $this->update([
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'attempts' => $this->attempts + 1,
            'error_message' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => NotificationStatus::Failed,
            'attempts' => $this->attempts + 1,
            'error_message' => $error,
        ]);
    }
}
