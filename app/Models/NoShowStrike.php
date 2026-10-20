<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One unexcused no-show against one client (decision D6).
 *
 * A record rather than a counter on the user, so the office can answer "why
 * is this resident blocked?" with a list rather than a number.
 *
 * @property int $id
 * @property int $user_id
 * @property int $appointment_id
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class NoShowStrike extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'appointment_id',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
