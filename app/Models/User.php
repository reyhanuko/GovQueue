<?php

namespace App\Models;

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
        'role',
        'phone',
        'address',
        'barangay',
        'birthdate',
        'is_active',
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
            // Hashes any plain value assigned to the attribute, so factories and
            // seeders can pass readable strings.
            'password' => 'hashed',
            'is_active' => 'boolean',
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
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
