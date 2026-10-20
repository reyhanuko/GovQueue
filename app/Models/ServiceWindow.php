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
     *
     * There is no field for setting this, and that is deliberate rather than an
     * oversight. The prefix is meant to be readable from across a lobby, so it
     * is four characters at most; and changing one does not renumber the
     * tickets already issued, it makes the same window issue two different
     * series - so the client holding A-014 has a ticket that no longer matches
     * the window's number. That is a decision about what an issued number
     * means, and it is not one to make by accident in a form field.
     *
     * So the prefix belongs on the window's creation form, and nowhere else.
     * Until that exists, every window issues under the default.
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
