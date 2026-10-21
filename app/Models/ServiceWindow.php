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
        'queue_prefix',
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

    /**
     * The prefix this window's queue numbers carry.
     *
     * Falls back to 'A' so a window row created before the column existed, or
     * inserted by a script that did not set one, keeps behaving as it always
     * did rather than issuing a number with no prefix at all.
     */
    public function queuePrefix(): string
    {
        // Trim first, then fall back. Checking for empty before trimming misses
        // a value that is only whitespace, which a blank form field produces -
        // and that issued "-001" rather than a numbered ticket.
        $prefix = strtoupper(trim((string) $this->queue_prefix));

        return $prefix === '' ? 'A' : $prefix;
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
