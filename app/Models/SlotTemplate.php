<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class SlotTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_minutes',
        'capacity',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'slot_minutes' => 'integer',
            'capacity' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function dayName(): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$this->day_of_week];
    }

    public function appliesOn(Carbon $date): bool
    {
        if ($date->dayOfWeek !== $this->day_of_week) {
            return false;
        }

        if ($date->lt(Carbon::parse($this->effective_from)->startOfDay())) {
            return false;
        }

        return $this->effective_to === null
            || $date->lte(Carbon::parse($this->effective_to)->endOfDay());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
