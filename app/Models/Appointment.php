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
        'priority_document_path',
        'priority_document_name',
        'priority_verified_at',
        'priority_verified_by',
        'priority_refused_at',
        'priority_refused_reason',
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
        'reminders_sent',
        'skip_count',
        'estimated_wait_minutes',
        'walk_in_dedupe_key',
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
            'priority_refused_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'called_at' => 'datetime',
            'serving_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'skip_count' => 'integer',
            'estimated_wait_minutes' => 'integer',
            'reminders_sent' => 'array',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Reminders */
    /* ------------------------------------------------------------------ */

    /**
     * Has the reminder for this many hours before already gone out?
     */
    public function hasSentReminder(int $hoursBefore): bool
    {
        return in_array($hoursBefore, $this->reminderOffsets(), true);
    }

    /**
     * Record that the reminder for this offset has been dispatched.
     *
     * Idempotent: calling twice for the same offset changes nothing.
     */
    public function markReminderSent(int $hoursBefore): void
    {
        $offsets = $this->reminderOffsets();

        if (in_array($hoursBefore, $offsets, true)) {
            return;
        }

        $offsets[] = $hoursBefore;
        sort($offsets);

        $this->forceFill([
            'reminders_sent' => $offsets,
            'reminder_sent_at' => $this->reminder_sent_at ?? now(),
        ])->save();
    }

    /**
     * @return array<int, int>
     */
    public function reminderOffsets(): array
    {
        return array_map('intval', (array) ($this->reminders_sent ?? []));
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

    /**
     * The categories this ticket claims, whether or not staff have decided.
     *
     * A claim and a decision are two different facts, and the screen used to
     * confuse them. `priorityLabels()` returned "Regular" for anything that
     * had not been verified yet, so a client who claimed a PWD entitlement
     * was told - on their own page, on the page they watch while they wait,
     * and by the clerk who had opened the panel to verify it - that they had
     * claimed nothing at all. The claim is real the moment it is made; what
     * is undecided is whether it is granted.
     *
     * @return string the claimed categories, or an empty string for none
     */
    public function claimedPriorityLabels(): string
    {
        $types = $this->claimedPriorities();

        if ($types === []) {
            return '';
        }

        return collect($types)->map(fn (PriorityType $t) => $t->label())->implode(', ');
    }

    /**
     * The categories staff have verified, and nothing else.
     *
     * @return string the verified categories, or an empty string for none
     */
    public function verifiedPriorityLabels(): string
    {
        return $this->hasVerifiedPriority() ? $this->claimedPriorityLabels() : '';
    }

    /**
     * What to say about this ticket's priority, in one line.
     *
     * "Regular" is a true statement about a client who claimed nothing. It is
     * a false statement about a client who claimed a PWD entitlement and is
     * waiting for the clerk to look at the document - and it is the sentence
     * a clerk reads when deciding whether a claim deserves the counter's
     * time, so a false one there is not a cosmetic problem.
     */
    public function priorityStatusLabel(): string
    {
        $claimed = $this->claimedPriorityLabels();

        if ($claimed === '') {
            return 'Regular';
        }

        if ($this->hasVerifiedPriority()) {
            return $claimed.' (verified)';
        }

        if ($this->hasRefusedPriority()) {
            return $claimed.' (refused)';
        }

        return $claimed.' (awaiting verification)';
    }

    /**
     * Has staff turned this claim down?
     *
     * The claim stays on the ticket when it is refused: the client did make
     * it, and the office did decide about it. What was refused is the grant,
     * not the fact of the claim - so the client can be told what was said,
     * and can be verified again if they come back with the right document.
     */
    public function hasRefusedPriority(): bool
    {
        return $this->priority_refused_at !== null;
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

    /**
     * Is this ticket still worth showing as "upcoming" to its client?
     *
     * There are two kinds of live ticket, and they are judged differently.
     *
     * A ticket the client has not arrived for - pending or confirmed - is
     * still their booking. They can cancel it, and they can move it, and
     * until the office closes it they are the only person who can release
     * the slot. Hiding it would leave them holding a booking they could
     * neither see nor cancel, so it stays on the list until it is closed.
     *
     * A ticket that holds a place in the line, or is at a counter, is
     * judged by its day. A ticket that was checked in on a slot that ended
     * last week is still CheckedIn - nobody closed it - so listing it told
     * the client they were still in a queue that ended days ago. The
     * end-of-day command is what closes those, and it runs hourly.
     *
     * This is a display rule and nothing more. It does not change any status.
     */
    public function isStillRelevant(): bool
    {
        if (! $this->status->isActive()) {
            return false;
        }

        if (! $this->status->isInLine() && ! $this->status->isAtTheOffice()) {
            return true;
        }

        $reference = $this->scheduled_at
            ?? $this->timeSlot?->end_at
            ?? $this->checked_in_at
            ?? $this->created_at;

        return $reference === null
            ? true
            : $reference->endOfDay()->gte(today());
    }

    /**
     * The key that makes one live walk-in per client, per service, per day.
     *
     * Held only while the ticket holds a place in the line, so a resident who
     * is served and walks in again later the same day is not stopped by their
     * own history.
     */
    public static function walkInDedupeKey(int $userId, int $serviceId, string $queueDate): string
    {
        return implode(':', [$userId, $serviceId, $queueDate]);
    }

    /**
     * When this client may check in. (decision D7)
     *
     * From half an hour before the slot starts until a quarter of an hour
     * after it ends. A walk-in is not booked into a slot, so it is never
     * outside a window - it was issued at the counter, which is where the
     * client is standing.
     *
     * One definition, because the rule is shown on the console as well as
     * enforced by the service, and two copies of a window is two chances for
     * the button and the rule to disagree.
     *
     * @return array{0: ?Carbon, 1: ?Carbon} opens at, closes at
     */
    public function checkInWindow(): array
    {
        $slot = $this->timeSlot;

        if (! $slot) {
            return [null, null];
        }

        return [
            $slot->start_at->copy()->subMinutes((int) config('govqueue.check_in_window.early_minutes', 30)),
            $slot->end_at->copy()->addMinutes((int) config('govqueue.check_in_window.late_minutes', 15)),
        ];
    }

    /**
     * Is this client inside their check-in window right now?
     */
    public function isCheckInWindowOpen(): bool
    {
        [$opensAt, $closesAt] = $this->checkInWindow();

        if (! $opensAt || ! $closesAt) {
            return false;
        }

        return now()->between($opensAt, $closesAt);
    }

    /**
     * Minutes the client actually waited between checking in and being served.
     */
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
        return $query->whereIn('status', AppointmentStatus::activeValues());
    }

    public function scopeOnQueueDate(Builder $query, $date): Builder
    {
        // Normalise so relative strings ("today", "next Monday") resolve in PHP
        // rather than being passed unparsed to the database.
        //
        // Fails closed rather than throwing. Carbon::parse() raises
        // InvalidFormatException on unparseable input, and this scope is reachable
        // from the public queue display with a hand-edited ?date= and no
        // authentication - so a typo became an anonymous, unthrottled 500 on a
        // screen meant to face the public. An impossible date matches nothing,
        // which is the correct answer: there is no such queue.
        try {
            $resolved = Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereDate('queue_date', $resolved);
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
