<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'payload',
        'ip_address',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Write an audit entry. Never throws - auditing must not break the request. */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?array $payload = null,
        ?int $userId = null,
    ): void {
        try {
            static::create([
                'user_id' => $userId ?? auth()->id(),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'payload' => $payload,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
