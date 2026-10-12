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
        'claims_senior_citizen',
        'is_active',
        'notify_email',
        'notify_sms',
        'service_window_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'birthdate' => 'date',
            'claims_senior_citizen' => 'boolean',
            // Hashes any plain value assigned to the attribute, so factories and
            // seeders can pass readable strings.
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notify_email' => 'boolean',
            'notify_sms' => 'boolean',
            'must_change_password' => 'boolean',
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
     * RA 9994: a senior citizen is 60 years of age or older.
     */
    public function meetsSeniorCitizenAge(): bool
    {
        return ($this->ageInYears() ?? 0) >= 60;
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
