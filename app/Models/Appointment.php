<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Services\QueueOrderingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'user_id',
        'service_id',
        'time_slot_id',
        'service_window_id',
        'status',
        'source',
        'scheduled_at',
        'queue_number',
        'queue_date',
        'claims_senior_citizen',
        'claims_pwd',
        'claims_pregnant',
        'priority_reference',
        'priority_verified_at',
        'priority_verified_by',
        'client_note',
        'staff_note',
        'cancel_reason',
        'confirmed_at',
        'checked_in_at',
        'called_at',
        'serving_started_at',
        'completed_at',
        'cancelled_at',
        'reminder_sent_at',
        'skip_count',
        'estimated_wait_minutes',
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'claims_senior_citizen' => 'boolean',
            'claims_pwd' => 'boolean',
            'claims_pregnant' => 'boolean',
            'scheduled_at' => 'datetime',
            'queue_date' => 'date',
            'priority_verified_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'called_at' => 'datetime',
            'serving_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'skip_count' => 'integer',
            'estimated_wait_minutes' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(ServiceWindow::class, 'service_window_id');
    }

    public function priorityVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'priority_verified_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(QueueStatusHistory::class)->orderBy('created_at');
    }

    public function transaction(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /* ------------------------------------------------------------------ */
    /* Priority handling (RA 9994 / RA 10754) */
    /* ------------------------------------------------------------------ */

    /**
     * The priority categories this appointment claims.
     *
     * @return array<int, PriorityType>
     */
    public function claimedPriorities(): array
    {
        $types = [];

        if ($this->claims_senior_citizen) {
            $types[] = PriorityType::SeniorCitizen;
        }
        if ($this->claims_pwd) {
            $types[] = PriorityType::Pwd;
        }
        if ($this->claims_pregnant) {
            $types[] = PriorityType::Pregnant;
        }

        return $types;
    }

    /**
     * A claim only grants precedence once staff have confirmed it. Until then
     * the client is queued as an ordinary client. See the project's stated
     * limitation: the system never verifies eligibility itself.
     */
    public function hasVerifiedPriority(): bool
    {
        return $this->priority_verified_at !== null && $this->claimedPriorities() !== [];
    }

    public function verifiedPriorityTypes(): array
    {
        return $this->hasVerifiedPriority() ? $this->claimedPriorities() : [];
    }

    public function priorityLabels(): string
    {
        $types = $this->hasVerifiedPriority()
            ? $this->claimedPriorities()
            : [];

        if ($types === []) {
            return 'Regular';
        }

        return collect($types)->map(fn (PriorityType $t) => $t->label())->implode(', ');
    }

    /* ------------------------------------------------------------------ */
    /* Display helpers */
    /* ------------------------------------------------------------------ */

    public function scheduledDateLabel(): string
    {
        return ($this->scheduled_at ?? $this->timeSlot?->slot_date)?->format('M d, Y') ?? 'Walk-in';
    }

    public function scheduledTimeLabel(): string
    {
        return $this->timeSlot?->timeRangeLabel() ?? 'As arrived';
    }

    /** Minutes the client actually waited between checking in and being served. */
    public function actualWaitMinutes(): ?int
    {
        if (! $this->checked_in_at || ! $this->serving_started_at) {
            return null;
        }

        return (int) round(($this->checked_in_at->diffInMinutes($this->serving_started_at)));
    }

    /** Minutes the staff member spent on this client. */
    public function actualServiceMinutes(): ?int
    {
        if (! $this->serving_started_at || ! $this->completed_at) {
            return null;
        }

        return (int) round(($this->serving_started_at->diffInMinutes($this->completed_at)));
    }

    /* ------------------------------------------------------------------ */
    /* Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AppointmentStatus::Pending->value,
            AppointmentStatus::Confirmed->value,
            AppointmentStatus::CheckedIn->value,
            AppointmentStatus::Serving->value,
            AppointmentStatus::Skipped->value,
        ]);
    }

    public function scopeOnQueueDate(Builder $query, $date): Builder
    {
        // Normalise so relative strings ("today", "next Monday") resolve in PHP
        // rather than being passed unparsed to the database.
        return $query->whereDate('queue_date', Carbon::parse($date)->toDateString());
    }

    public function scopeForWindow(Builder $query, $windowId): Builder
    {
        return $query->where('service_window_id', $windowId);
    }

    public function scopePriority(Builder $query): Builder
    {
        return $query->whereNotNull('priority_verified_at')
            ->where(function (Builder $q) {
                $q->where('claims_senior_citizen', true)
                    ->orWhere('claims_pwd', true)
                    ->orWhere('claims_pregnant', true);
            });
    }

    /** Arrivals only: tickets that physically need a window right now. */
    public function scopeOnSite(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AppointmentStatus::CheckedIn->value,
            AppointmentStatus::Skipped->value,
        ]);
    }

    /**
     * The canonical queue order, delegated to the single ordering service so
     * the staff console, the client view, and the public display can never
     * disagree about who is next.
     */
    public function scopeInQueueOrder(Builder $query, ?string $queueDate = null): Builder
    {
        return app(QueueOrderingService::class)->applyOrder(
            $query->onQueueDate($queueDate ?? today())
        );
    }
}
