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

    /**
     * Templates that can still match a date from today.
     *
     * Decision D1 sums the window's live templates to work out how many places it
     * is offering. A template whose range has closed is configuration history:
     * it generates nothing, so counting it would keep warning about a problem an
     * administrator has already resolved.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', today())
            ->where(function (Builder $q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', today());
            });
    }
}
