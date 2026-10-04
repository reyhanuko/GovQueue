<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'service_window_id',
        'slot_date',
        'start_time',
        'end_time',
        'start_at',
        'end_at',
        'capacity',
        'booked_count',
        'is_open',
    ];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(ServiceWindow::class, 'service_window_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function remainingCapacity(): int
    {
        return max(0, $this->capacity - $this->booked_count);
    }

    public function isFull(): bool
    {
        return $this->remainingCapacity() <= 0;
    }

    public function isBookable(): bool
    {
        return $this->is_open && ! $this->isFull();
    }

    public function startDateTime(): ?Carbon
    {
        return $this->start_at;
    }

    public function timeRangeLabel(): string
    {
        return $this->formatTime($this->start_time).' - '.$this->formatTime($this->end_time);
    }

    public function formatTime(?string $time): string
    {
        if (! $time) {
            return '';
        }

        return Carbon::parse($time)->format('g:i A');
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query
            ->where('is_open', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->whereDate('slot_date', '>=', today()->toDateString());
    }

    /**
     * Normalise the $date argument to a Y-m-d string.
     *
     * Without this, whereDate() would hand a human string such as "next Monday"
     * straight to MySQL, which cannot parse it and silently matches nothing.
     */
    protected function normalizeDate($date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    public function scopeForDate(Builder $query, $date): Builder
    {
        return $query->whereDate('slot_date', $this->normalizeDate($date));
    }
}
