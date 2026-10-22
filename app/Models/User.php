<?php

namespace App\Models;

use App\Enums\PriorityType;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'role',
        'phone',
        'address',
        'barangay',
        'birthdate',
        'is_active',
        'notify_email',
        'notify_sms',
        'booking_blocked_until',
        'service_window_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Writes to a derived attribute are refused rather than stored.
     *
     * `claims_senior_citizen` is computed from the date of birth (see
     * getClaimsSeniorCitizenAttribute), so there is no column to write it to.
     * Letting the write through would put an attribute on the model that the
     * database does not have, which fails at the INSERT with an error nobody
     * can read.
     *
     * A mass-assigned value is discarded here as well as by $fillable, so a
     * crafted registration or profile post cannot set a priority flag even if
     * the validation is bypassed.
     */
    public function setAttribute($key, $value)
    {
        if ($key === 'claims_senior_citizen') {
            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'birthdate' => 'date',
            // Hashes any plain value assigned to the attribute, so factories and
            // seeders can pass readable strings.
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notify_email' => 'boolean',
            'notify_sms' => 'boolean',
            'must_change_password' => 'boolean',
            'booking_blocked_until' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    public function serviceWindow(): BelongsTo
    {
        return $this->belongsTo(ServiceWindow::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * The unexcused no-shows recorded against this client (decision D6).
     */
    public function noShowStrikes(): HasMany
    {
        return $this->hasMany(NoShowStrike::class);
    }

    /**
     * Is this client blocked from making new bookings?
     *
     * A thin question over the column, so the screen and the service ask the
     * same thing. The policy itself lives in App\Services\NoShowPolicy.
     */
    public function isBookingBlocked(): bool
    {
        return $this->booking_blocked_until !== null
            && $this->booking_blocked_until->isFuture();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /** Staff and administrators may both reach the back office. */
    public function isBackOffice(): bool
    {
        return $this->isStaff() || $this->isAdmin();
    }

    /* ------------------------------------------------------------------ */
    /* Notification preferences (Sprint 9) */
    /* ------------------------------------------------------------------ */

    /**
     * Has this client opted in to email?
     *
     * Until Sprint 9 every client received everything, decided only by
     * office-wide config. A resident with no phone, no credit, or no interest in
     * texts had no say, which is both a practical problem and a privacy one.
     *
     * The null fallback matters for rows created before the migration: a null
     * column must not read as "opted out" and silently stop sending to everyone.
     */
    public function wantsEmail(): bool
    {
        return (bool) ($this->notify_email ?? true);
    }

    /**
     * Has this client opted in to SMS?
     *
     * Deliberately not also gated on having a number on file. A client with no
     * mobile number still produces a delivery-log row explaining why, which is
     * how staff find out they cannot text someone - that behaviour was chosen in
     * Sprint 6 and this preference is about consent, not reachability.
     */
    public function wantsSms(): bool
    {
        return (bool) ($this->notify_sms ?? true);
    }

    /**
     * Is this client reachable outside the site at all?
     *
     * Used to warn someone who has turned off everything that they will only see
     * reminders by signing in.
     */
    public function hasNoOutboundChannel(): bool
    {
        return ! $this->wantsEmail() && ! $this->wantsSms();
    }

    /** Age in whole years, used for the RA 9994 senior-citizen check. */
    public function ageInYears(): ?int
    {
        return $this->birthdate?->age;
    }

    /**
     * The RA 9994 threshold, in whole years. Sixty, unless configured otherwise.
     */
    public function seniorCitizenAgeThreshold(): int
    {
        return (int) config('govqueue.senior_citizen_age', 60);
    }

    /**
     * RA 9994: a senior citizen is 60 years of age or older.
     *
     * Read from the date of birth on file, never from a stored flag, so the
     * entitlement cannot drift away from the fact it is derived from.
     */
    public function meetsSeniorCitizenAge(): bool
    {
        return ($this->ageInYears() ?? 0) >= $this->seniorCitizenAgeThreshold();
    }

    /**
     * Is this client a senior citizen under RA 9994?
     *
     * A derived attribute, not a column: the entitlement *is* the date of birth
     * on file, so it is recomputed on every read. There is nothing to set, and
     * nothing that can go stale.
     *
     * PWD (RA 10754) and pregnancy are deliberately absent from the account.
     * Both are per-transaction claims made against a document when an
     * appointment is booked, so neither belongs on the user record.
     */
    public function getClaimsSeniorCitizenAttribute($value = null): bool
    {
        return $this->meetsSeniorCitizenAge();
    }

    /** Admin accounts are never deactivated as a side effect of the staff screens. */
    /**
     * Priority categories this client may claim on the strength of their own
     * profile alone. See App\Services\ClientProfileService for the rules.
     *
     * @return array<int, string>
     */
    public function profilePriorityCategories(): array
    {
        return $this->claims_senior_citizen
            ? [PriorityType::SeniorCitizen->value]
            : [];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
