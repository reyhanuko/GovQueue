<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_window_id',
        'name',
        'code',
        'description',
        'requirements',
        'fee',
        'duration_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(ServiceWindow::class, 'service_window_id');
    }

    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class);
    }

    public function slotTemplates(): HasMany
    {
        return $this->hasMany(SlotTemplate::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function requirementsList(): array
    {
        if (blank($this->requirements)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $this->requirements))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
