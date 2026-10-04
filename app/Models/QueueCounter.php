<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-window, per-day queue number sequence.
 *
 * The row is written with lockForUpdate() by BookingService so concurrent
 * bookings cannot be handed the same queue number.
 */
class QueueCounter extends Model
{
    protected $fillable = [
        'service_window_id',
        'queue_date',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'last_number' => 'integer',
        ];
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(ServiceWindow::class, 'service_window_id');
    }
}
