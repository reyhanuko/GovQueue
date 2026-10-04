<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'avg_service_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'avg_service_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
