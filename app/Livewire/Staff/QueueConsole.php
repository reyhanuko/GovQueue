<?php

namespace App\Livewire\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Exceptions\AppointmentConflictException;
use App\Exceptions\CheckInWindowException;
use App\Exceptions\InvalidQueueTransitionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Appointment as Ticket;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Services\BookingService;
use App\Services\QueueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The live counter console.
 *
 * Everything happens in-place: call the next ticket, serve, complete, skip, or
 * verify a priority claim, without a page reload. Order comes from
 * QueueOrderingService so statutory priority is applied automatically instead of
 * depending on the operator's memory of who is a senior citizen.
 */
class QueueConsole extends Component
{
    #[Url(as: 'date', keep: false)]
    public string $queueDate = '';

    #[Url(as: 'w')]
    public string $windowCode = '';

    /**
     * The panel that is open over the console, if any.
     *
     * 'verify'     - deciding a priority claim
     * 'no_show'    - confirming a no-show
     * 'not_complete' - recording a transaction that could not be completed
     *
     * A dialog on the page, not the browser's own: the browser's confirm() is
     * an operating-system window that says one sentence and offers no room to
     * write down why. These decisions need a reason, and the reason goes to
     * the client.
     */
    public string $modal = '';

    /** Ticket selected for the priority-verification panel. */
    public ?int $verifyingId = null;

    /**
     * The outcome of the last action, per panel.
     *
     * The console used to close a panel and put a green banner at the top of
     * the page, which the clerk had to scroll up to find - and which hid
     * itself after eight seconds. On a counter machine, the confirmation of
     * the thing you just did belongs where you did it, and it stays until you
     * have read it.
     *
     * @var array<string, array{kind: string, text: string}>
     */
    public array $outcomes = [];

    /**
     * The refusal sub-panel is open.
     *
     * Refusing a claim is not the same act as verifying one, and it is not
     * the same kind of act: it needs a reason, and the reason goes to the
     * client. So it gets its own field, its own button, and its own words
     * about what will happen.
     */
    public bool $refusingPriority = false;

    /**
     * Why the claim was refused.
     *
     * Required, and shown to the client on their appointment page. A refusal
     * the client cannot read the reason for is a refusal they cannot act on.
     */
    public string $priorityRefusalReason = '';

    /**
     * The ticket a no-show is being confirmed for.
     *
     * A no-show is recorded against a client's record and, for a client who
     * simply did not come, counts towards the strikes that pause their
     * bookings. That is worth a deliberate click rather than a stray one.
     */
    public ?int $noShowingId = null;

    /** The note the clerk writes on a no-show. */
    public string $noShowNote = '';

    /**
     * The ticket a "not completed" is being recorded for.
     */
    public ?int $notCompletingId = null;

    /**
     * Why the transaction could not be completed.
     *
     * Required, and shown to the client: it is what they do next. "Not
     * completed" on its own tells a client nothing they can act on.
     */
    public string $notCompletedReason = '';

    /* ------------------------------------------------------------------ */
    /* Walk-in creation */
    /* ------------------------------------------------------------------ */

    public bool $walkInOpen = false;

    public int $walkInServiceId = 0;

    public string $walkInName = '';

    public string $walkInPhone = '';

    /** Set once a duplicate is detected, so the staff override can act on it. */
    public ?int $walkInDuplicateId = null;

    public bool $walkInOverride = false;

    /**
     * Statutory categories the walk-in says they belong to.
     *
     * Recorded as a *claim*, not as verified priority. A walk-in has no account
     * to carry a claim, so before this existed the only way a senior citizen
     * arriving without a booking could get any recognition was for staff to
     * edit the database - and RA 9994 applies to them as much as to anybody.
     *
     * The claim confers nothing on its own: it goes through exactly the same
     * verification step as a client's own claim, and QueueOrderingService still
     * only reorders once priority_verified_at is set.
     *
     * @var array<int, string>
     */
    public array $walkInClaims = [];

    /**
     * The reference for each claim recorded at the counter.
     *
     * One per claim, as for a client's own claim: a PWD ID and a medical
     * certificate are different documents and the staff have to be able to
     * tell which is which.
     */
    public array $walkInReferences = [];

    public ?int $servingId = null;

    public ?int $completingId = null;

    public string $transactionNote = '';

    public string $feePaid = '';

    public string $priorityNote = '';

    /**
     * The configured fee of the ticket in the completion panel.
     *
     * Public rather than derived in the view because the view needs it twice:
     * to decide whether the service is free at all, and to compare what staff
     * typed against what the service is set at.
     */
    public float $completingServiceFee = 0.0;

    public ?string $feedback = null;

    public ?string $error = null;

    public function mount(): void
    {
        // Defense in depth: the route is already wrapped in role:staff,admin,
        // but a component that renders the counter controls should never
        // rely on routing alone.
        abort_unless(auth()->user()?->isBackOffice(), 403);

        $this->queueDate = $this->queueDate ?: today()->toDateString();

        // Staff work their own window. Opening somebody else's counter is an
        // administrator's job, so a staffer's console is pinned to theirs -
        // otherwise Rosa could work Window 3, and the queue numbers she issues
        // would collide with the clerk actually sitting there.
        $this->windowCode = $this->assignedWindow?->code
            ?? ServiceWindow::where('is_active', true)->value('code')
            ?? '';
    }

    /**
     * The window this member of staff is assigned to, if any.
     *
     * Null for an administrator, who is allowed to open any window's console.
     */
    #[Computed]
    public function assignedWindow(): ?ServiceWindow
    {
        $user = auth()->user();

        return $user?->isStaff() ? $user->serviceWindow : null;
    }

    /**
     * The windows this operator may open.
     *
     * One for a staffer, all of them for an administrator.
     */
    #[Computed]
    public function selectableWindows()
    {
        $assigned = $this->assignedWindow;

        if ($assigned) {
            return collect([$assigned]);
        }

        return $this->windows;
    }

    /** Can the signed-in operator switch windows at all? */
    #[Computed]
    public function canSwitchWindow(): bool
    {
        return $this->assignedWindow === null;
    }

    public function updatedWindowCode(): void
    {
        // Enforced on the server as well as hidden in the UI: the select is a
        // convenience, this is the rule.
        if (! $this->canSwitchWindow && $this->windowCode !== $this->assignedWindow?->code) {
            $this->windowCode = $this->assignedWindow?->code ?? $this->windowCode;
            $this->error = 'Your console is limited to your assigned window.';

            return;
        }

        // A different window means a different queue; drop any open panel.
        $this->reset(['servingId', 'completingId', 'verifyingId']);
    }

    /**
     * Called when the window's queue changes on another device or from the
     * public display. Just nudges a re-render; the queries are the same ones the
     * operator's own actions trigger.
     */
    public function onQueueUpdated(): void
    {
        $this->dispatch('$refresh');
    }

    /* ------------------------------------------------------------------ */
    /* Computed views */
    /* ------------------------------------------------------------------ */

    /*
     * Every computed below must be read as a *property*: $this->window, never
     * $this->window().
     *
     * That distinction is the whole reason this class is not 39 queries a render.
     * Livewire's #[Computed] memoises property access for the request. A direct
     * method call bypasses the memo and re-runs the query. Every internal caller
     * here used to call the method, so a single page load resolved the same window
     * 15 times and re-ran the identical ordered queue query 4 times - once for the
     * panel, once for the estimates, once for the stats panel, and again because
     * stats() asked for the queue twice.
     *
     * A previous version of this comment claimed that calling a computed as a
     * method throws by design, making the mistake a loud failure rather than a
     * silent slowdown. That was not true and was worse than no comment at all: it
     * stopped anyone checking. Livewire does define
     * CannotCallComputedDirectlyException, but nothing routes a plain method call
     * to it - #[Computed] hooks magic property access, and the underlying public
     * method stays an ordinary callable method.
     *
     * Verified: calling a computed as a method returns normally and re-runs the
     * query. So the mistake is silent, which is why the query count is pinned by
     * QueueConsoleQueryCountTest instead of being left to code review. That test
     * is the safety net, not this comment.
     */

    #[Computed]
    public function windows(): Collection
    {
        return ServiceWindow::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function window(): ?ServiceWindow
    {
        return ServiceWindow::where('code', $this->windowCode)->first();
    }

    /**
     * The ticket at the window, or null.
     *
     * Returns an array rather than a nullable model for one specific reason:
     * Livewire memoises #[Computed] with `$value ??= ...`, so a computed that
     * returns null is re-evaluated on every single access. The blade reads
     * `serving` twice (the panel, and the "call next anyway" button), so the
     * window lookup ran twice on every render - and an empty window, which is the
     * most common state at a counter between clients, re-ran it every time.
     *
     * Wrapping the nullable in a non-null array means the memo sticks.
     *
     * @return array{ticket: ?Ticket}
     */
    #[Computed]
    public function servingState(): array
    {
        return [
            'ticket' => $this->window
                ? $this->queue()->currentlyServing($this->window, $this->queueDate)
                : null,
        ];
    }

    #[Computed]
    public function serving(): ?Ticket
    {
        return $this->servingState['ticket'];
    }

    #[Computed]
    public function waiting(): Collection
    {
        if (! $this->window) {
            return collect();
        }

        return $this->queue()->waitingFor($this->window, $this->queueDate);
    }

    /**
     * The bookings for this window that have not been checked in.
     *
     * The whole set, and the screen shows the first few. A capped query and
     * a separate count would be two round trips to answer one number, and a
     * counter machine feels every one of them.
     */
    #[Computed]
    public function expected(): Collection
    {
        if (! $this->window) {
            return collect();
        }

        return $this->queue()->expectedButNotArrived($this->window, $this->queueDate);
    }

    #[Computed]
    public function estimates(): array
    {
        if (! $this->window) {
            return [];
        }

        // The window's average is handed in rather than re-measured, so the figure
        // in the stats panel and the estimates below it cannot disagree.
        return $this->queue()->estimateWaits($this->window, $this->waiting, $this->avgServiceMinutes);
    }

    /**
     * The window's measured average service time, in minutes.
     *
     * A float rather than a nullable so Livewire's `??=` memo actually holds, and
     * exposed as its own computed because both the stats panel and the per-client
     * wait estimates need it. Reading it once keeps the "avg service" headline and
     * the estimates derived from the same measurement - which matters, because two
     * queries a few milliseconds apart could straddle a completion and show a
     * headline that disagrees with the numbers under it.
     */
    #[Computed]
    public function avgServiceMinutes(): float
    {
        return $this->window
            ? $this->queue()->averageServiceMinutes($this->window)
            : 0.0;
    }

    /**
     * Headline numbers for the day at this window.
     *
     * Served and no-shows come from one grouped aggregate rather than two counts.
     * Three round trips to answer "two numbers" is the sort of thing that is
     * invisible in development and obvious on a counter machine in a municipal
     * office, where every query is a client waiting to be called.
     */
    #[Computed]
    public function stats(): array
    {
        $window = $this->window;

        if (! $window) {
            return [
                'served' => 0,
                'no_shows' => 0,
                'waiting' => 0,
                'priority' => 0,
                'avg_service' => 0,
            ];
        }

        $counts = Appointment::forWindow($window->id)
            ->whereDate('queue_date', $this->queueDate)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'served' => (int) ($counts[AppointmentStatus::Completed->value] ?? 0),
            'no_shows' => (int) ($counts[AppointmentStatus::NoShow->value] ?? 0),
            // The queue is already loaded for the waiting list, so these come from
            // it rather than from two more queries.
            'waiting' => $this->waiting->count(),
            'priority' => $this->waiting->filter->hasVerifiedPriority()->count(),
            'avg_service' => round($this->avgServiceMinutes, 1),
        ];
    }

    /**
     * Appointments lost from this window recently.
     *
     * Cancellations and no-shows are the two ways a booked day quietly becomes
     * shorter than the schedule, and neither leaves anything on the console's
     * main list - a cancelled ticket simply stops being there. Someone covering
     * the counter cannot see that a slot freed up, or that a client did not
     * arrive, without opening a report.
     *
     * A panel rather than a notification on purpose: staff were kept to
     * messages about themselves, and this belongs where they already work. It
     * also cannot go stale the way an inbox does - it reflects the selected day
     * every time the console is opened.
     *
     * Bounded to this window, the selected date, and the two statuses that mean
     * "not happening", so it never lists work still to do.
     *
     * The last eight only. While the day is being worked, this is a working
     * list - the counter wants to know who just walked out - and eight is
     * enough to see. Once the day is over it is a record, and a record of a
     * day that lost nine people is not a record if it names eight of them, so
     * the cap comes off.
     */
    #[Computed]
    public function recentlyLost(): Collection
    {
        $window = $this->window;

        if (! $window) {
            return collect();
        }

        return Appointment::forWindow($window->id)
            ->whereDate('queue_date', $this->queueDate)
            ->whereIn('status', [
                AppointmentStatus::Cancelled->value,
                AppointmentStatus::NoShow->value,
            ])
            ->with(['user', 'service'])
            ->latest('updated_at')
            ->when(! $this->selectedDayIsOver, fn ($query) => $query->limit(8))
            ->get();
    }

    /**
     * The selected day has to be today before anything on it can be worked.
     *
     * A day that is over is a record, and a day that has not come is a booking
     * list. Neither is a queue, so neither can have a client called, served,
     * skipped, checked in or given a priority claim.
     *
     * The rule is here rather than in QueueService, and that is not a
     * convenience. The service has no notion of a selected day, and it moves
     * tickets on days that are over on purpose: the hourly job that closes
     * yesterday's stale tickets is the same service call the console makes. The
     * console is the only place that knows which day the operator is working,
     * so the console is the only place the rule can live.
     */
    private function guardTheDayIsOpen(): bool
    {
        if ($this->lookingAtToday) {
            return true;
        }

        $this->error = $this->selectedDayIsOver
            ? $this->selectedDayLabel.' is over. A finished day is a record, and it cannot be changed.'
            : $this->selectedDayLabel.' has not come yet, so there is no queue to work.';

        return false;
    }

    /**
     * Is the console looking at today?
     *
     * Every queue action acts on the selected day, so on any other day there
     * is no queue to work - a day that is over is a record, and a day that has
     * not come is a booking list.
     */
    #[Computed]
    public function lookingAtToday(): bool
    {
        return $this->queueDate === today()->toDateString();
    }

    /**
     * Has the selected day been and gone?
     */
    #[Computed]
    public function selectedDayIsOver(): bool
    {
        return $this->queueDate < today()->toDateString();
    }

    /**
     * The selected day, in words.
     */
    #[Computed]
    public function selectedDayLabel(): string
    {
        return Carbon::parse($this->queueDate)->format('l j F Y');
    }

    /**
     * Who was served on the selected day, and how long each took.
     *
     * The history a clerk wants from a day that is over: not a queue to work,
     * but a record of what happened - who was served, when, and how long the
     * counter was on them.
     */
    #[Computed]
    public function servedOnSelectedDay(): Collection
    {
        $window = $this->window;

        if (! $window) {
            return collect();
        }

        return Appointment::with(['user', 'service'])
            ->forWindow($window->id)
            ->whereDate('queue_date', $this->queueDate)
            ->where('status', AppointmentStatus::Completed->value)
            ->orderBy('completed_at')
            ->get();
    }

    /**
     * Tickets for the selected day that were never resolved.
     *
     * Everything still open: booked but not confirmed, confirmed but nobody
     * came, checked in and never served, called and never finished, or sent
     * back and never reached. The hourly job closes the ones whose slot has
     * ended, but a day that is over can still have one left over, and a record
     * that is missing a name is not a record.
     */
    #[Computed]
    public function unresolvedOnSelectedDay(): Collection
    {
        $window = $this->window;

        if (! $window) {
            return collect();
        }

        return Appointment::with(['user', 'service'])
            ->forWindow($window->id)
            ->whereDate('queue_date', $this->queueDate)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::CheckedIn->value,
                AppointmentStatus::Serving->value,
                AppointmentStatus::Skipped->value,
            ])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Services that can take a walk-in at this window.
     *
     * Scoped to the window so the ticket lands in the queue the operator is
     * actually looking at.
     */
    #[Computed]
    public function walkInServices()
    {
        if (! $this->window) {
            return collect();
        }

        return Service::where('service_window_id', $this->window->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** The waiting ticket the walk-in was flagged as a duplicate of. */
    #[Computed]
    public function walkInDuplicate(): ?Ticket
    {
        return $this->walkInDuplicateId ? Ticket::with('user')->find($this->walkInDuplicateId) : null;
    }

    public function openWalkIn(): void
    {
        abort_unless(auth()->user()?->isBackOffice(), 403);

        $this->reset($this->walkInFields());

        $this->walkInOpen = true;
        $this->walkInServiceId = $this->walkInServices->first()?->id ?? 0;
        $this->resetFeedback();
    }

    public function cancelWalkIn(): void
    {
        $this->walkInOpen = false;
        $this->reset($this->walkInFields());
    }

    /**
     * The walk-in fields that must be cleared between tickets.
     *
     * Listed in one place because they were previously named twice, in
     * openWalkIn() and cancelWalkIn(), and the priority fields added later were
     * the obvious thing to forget - leaving the previous walk-in's claimed
     * categories pre-ticked on the next form, which would mean granting
     * RA 9994 priority to whoever arrived next without anybody asking.
     *
     * @return array<int, string>
     */
    private function walkInFields(): array
    {
        return [
            'walkInName',
            'walkInPhone',
            'walkInDuplicateId',
            'walkInOverride',
            'walkInClaims',
            'walkInReferences',
        ];
    }

    /**
     * Issue a walk-in ticket.
     *
     * The duplicate guard lives in BookingService so it cannot be bypassed by
     * any other caller; this catches the exception and turns it into the panel's
     * inline message, keeping the typed values so staff can act on the override
     * without retyping them.
     */
    public function createWalkIn(): void
    {
        abort_unless(auth()->user()?->isBackOffice(), 403);

        $this->validate([
            'walkInServiceId' => ['required', 'integer', 'exists:services,id'],
            'walkInName' => ['required', 'string', 'max:120'],
            'walkInPhone' => ['nullable', 'string', 'max:40'],
            'walkInClaims' => ['array'],
            'walkInClaims.*' => [Rule::in([
                PriorityType::SeniorCitizen->value,
                PriorityType::Pwd->value,
                PriorityType::Pregnant->value,
            ])],
            'walkInReferences' => ['nullable', 'array'],
            'walkInReferences.*' => ['nullable', 'string', 'max:120'],
        ]);

        $service = $this->walkInServices->firstWhere('id', (int) $this->walkInServiceId);

        if (! $service) {
            $this->error = 'That service is not available at this window.';

            return;
        }

        try {
            $appointment = app(BookingService::class)->createWalkIn(
                [
                    'name' => $this->walkInName,
                    'phone' => $this->walkInPhone,
                    'service_id' => $service->id,
                    'claims' => $this->walkInClaims,
                    'priority_reference' => PriorityType::joinReferences(
                        array_filter(
                            (array) $this->walkInReferences,
                            fn ($value, $key) => in_array($key, $this->walkInClaims, true),
                            ARRAY_FILTER_USE_BOTH,
                        ),
                    ),
                ],
                auth()->user(),
                $this->walkInOverride,
            );
        } catch (AppointmentConflictException $e) {
            $this->walkInDuplicateId = $e->conflicting?->id;
            $this->error = $e->getMessage();
            $this->feedback = null;

            return;
        } catch (SlotUnavailableException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            // The service raises these for claim-specific rules the form's own
            // validation cannot know about - e.g. a PWD claim with no reference.
            // Rendered here rather than allowed to become an exception page,
            // because staff are mid-ticket and the form must keep its values.
            $this->error = collect($e->errors())->flatten()->first()
                ?? 'That walk-in could not be issued.';
            $this->feedback = null;

            return;
        }

        $this->feedback = 'Walk-in '.$appointment->queue_number.' issued for '
            .$appointment->user->name.'.';

        if ($this->walkInOverride) {
            $this->feedback .= ' Duplicate confirmed and recorded in the audit trail.';
        }

        /*
         * Stated plainly, because "recorded" sounds like "granted" and the
         * difference matters. The claim is on the ticket and still has to be
         * verified like any other - the queue has not been reordered.
         */
        if ($appointment->claimedPriorities() !== []) {
            $this->feedback .= ' Priority claim recorded ('.$appointment->claimedPriorityLabels()
                .') - it takes effect once you verify it, so the queue is unchanged until then.';
        }

        $this->error = null;
        $this->cancelWalkIn();
    }

    #[Computed]
    public function verifying(): ?Ticket
    {
        return $this->verifyingId
            ? Appointment::with(['user', 'service'])->find($this->verifyingId)
            : null;
    }

    public function queue(): QueueService
    {
        return app(QueueService::class);
    }

    public function setDate(string $date): void
    {
        $this->queueDate = $date;
        $this->reset(['servingId', 'completingId', 'verifyingId']);
    }

    /* ------------------------------------------------------------------ */
    /* Actions */
    /* ------------------------------------------------------------------ */

    public function callNext(): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        $window = $this->window;

        if (! $window) {
            $this->error = 'No service window is selected.';

            return;
        }

        $actor = auth()->user();

        try {
            $called = $this->queue()->callNext($window, $actor, $this->queueDate);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($called === null) {
            $this->error = $this->serving
                ? 'Finish serving the current client before calling the next one.'
                : 'Nobody is waiting at this window.';

            return;
        }

        $this->feedback = 'Now serving '.$called->queue_number.' - '.$called->user->name;
        $this->error = null;
    }

    /**
     * Bring a client who has not checked in yet into the line.
     */
    /**
     * Call a skipped client back to the window.
     *
     * Their place in the order is kept: a skipped verified-priority client stays
     * ahead of most of the line (see QueueOrderingService::applyPriorityReinsertion),
     * so this is a shortcut for when they are standing at the counter, not a way
     * to jump the queue generally.
     */
    public function callBack(int $appointmentId): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        abort_unless(auth()->user()?->isBackOffice(), 403);

        $appointment = $this->findTicket($appointmentId);

        if (! $appointment) {
            return;
        }

        try {
            $this->guardWindow($appointment);

            $called = $this->queue()->callSpecific($appointment, auth()->user());
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if (! $called) {
            $this->error = $this->serving
                ? 'Finish serving '.$this->serving->queue_number.' before calling anyone else.'
                : 'That ticket cannot be called back right now.';

            return;
        }

        $this->feedback = 'Called '.$called->queue_number.' back to the window - '.$called->user->name.'.';
        $this->error = null;
    }

    public function checkIn(int $appointmentId): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        $appointment = $this->findTicket($appointmentId);

        if (! $appointment) {
            return;
        }

        try {
            $this->guardWindow($appointment);
            $this->queue()->checkIn($appointment, auth()->user());
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (CheckInWindowException $e) {
            /*
             * The check-in window (decision D7) is enforced by the service, so
             * a clerk pressing the button outside the window gets the rule
             * explained rather than a refusal they cannot repeat to the person
             * in front of them.
             */
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = $appointment->queue_number.' checked in.';
        $this->error = null;
    }

    public function skip(int $appointmentId): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        $appointment = $this->findTicket($appointmentId);

        if (! $appointment) {
            return;
        }

        try {
            $this->guardWindow($appointment);
            $this->queue()->skip($appointment, auth()->user());
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = $appointment->queue_number.' moved back in the line.';

        if ($this->queue()->hasBeenSkippedTooOften($appointment->fresh())) {
            $this->feedback .= ' Skipped '.config('govqueue.max_skips')
                .' times - consider marking this client a no-show.';
        }

        $this->error = null;
    }

    public function openComplete(int $appointmentId): void
    {
        $appointment = $this->findTicket($appointmentId);

        if (! $appointment) {
            return;
        }

        try {
            $this->guardWindow($appointment);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->completingId = $appointmentId;
        $this->transactionNote = '';

        // Keep the configured fee separately so the panel can tell the
        // difference between "this service is free" and "staff typed zero",
        // which look identical in a number box.
        $this->completingServiceFee = (float) $appointment->service->fee;

        $this->feePaid = (string) $this->completingServiceFee;
    }

    public function complete(): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        if (! $this->completingId) {
            return;
        }

        $appointment = $this->findTicket($this->completingId);

        if (! $appointment) {
            $this->completingId = null;

            return;
        }

        try {
            $this->validate([
                'feePaid' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'transactionNote' => ['nullable', 'string', 'max:1000'],
            ]);

            // Always send a number: null here would be read by the Reports
            // totals as "no fee data", which is not the same as a free service.
            $feePaid = $this->feePaid === '' || $this->feePaid === null
                ? 0.0
                : (float) $this->feePaid;

            $this->guardWindow($appointment);
            $this->queue()->complete($appointment, auth()->user(), [
                'details' => $this->transactionNote !== '' ? ['note' => $this->transactionNote] : null,
                'fee_paid' => $feePaid,
            ]);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        // Reports sums transactions.fee_paid, so an unrecorded amount silently
        // disappears from Fees collected. A free service records an explicit 0
        // rather than null, which keeps "nothing was charged" distinguishable
        // from "we never recorded what was charged".
        $this->feedback = 'Transaction recorded for '.$appointment->queue_number.'.';

        if ($this->completingServiceFee > 0 && abs($feePaid - $this->completingServiceFee) > 0.009) {
            $this->feedback .= ' Fee recorded as '.number_format($feePaid, 2).' rather than the standard '
                .number_format($this->completingServiceFee, 2).'.';
        }
        $this->error = null;
        $this->completingId = null;
    }

    public function markNoShow(): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        $serving = $this->serving;

        if (! $serving) {
            return;
        }

        try {
            $this->queue()->markNoShow($serving, auth()->user());
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = $serving->queue_number.' marked as a no-show.';
        $this->error = null;
    }

    /**
     * A booked client who never turned up.
     *
     * The console's own no-show button only reaches the client at the window.
     * A client who was booked, did not come, and whose slot has now passed is
     * a different thing: nobody can mark them, so the ticket sits in "booked
     * but not yet checked in" until the hourly close-out eventually reaches it
     * - and in the meantime the counter is holding a place for somebody who
     * is not there.
     *
     * The slot is released, the ticket is recorded as a no-show, and - because
     * the client never came and never cancelled - it counts towards the
     * strikes that pause their bookings.
     */
    public function markExpectedNoShow(int $appointmentId): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        $appointment = $this->findTicket($appointmentId);

        if (! $appointment) {
            return;
        }

        try {
            $this->guardWindow($appointment);
            $this->queue()->markNoShow(
                $appointment,
                auth()->user(),
                'Client did not arrive for their booked slot.',
            );
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = $appointment->queue_number.' marked as a no-show.';
        $this->error = null;
    }

    public function openVerify(int $appointmentId): void
    {
        $appointment = $this->findTicket($appointmentId);

        if (! $appointment) {
            return;
        }

        try {
            $this->guardWindow($appointment);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->verifyingId = $appointmentId;
        $this->priorityNote = $appointment->priority_reference ?? '';
        $this->priorityRefusalReason = '';
        $this->refusingPriority = false;
        $this->modal = 'verify';
        unset($this->outcomes['verify']);
    }

    /**
     * Open the refusal sub-panel.
     *
     * Refusing is a separate act from verifying, with a separate field: the
     * clerk writes why, and the client is told why. A button that refused a
     * statutory claim on a single click, with the reason left blank, was the
     * kind of decision that gets made badly.
     */
    public function openRefuse(): void
    {
        if (! $this->verifyingId) {
            return;
        }

        $this->refusingPriority = true;
        $this->priorityRefusalReason = '';
        unset($this->outcomes['verify']);
    }

    public function cancelRefuse(): void
    {
        $this->refusingPriority = false;
        $this->priorityRefusalReason = '';
    }

    /**
     * Close the verification panel.
     */
    public function closeVerify(): void
    {
        $this->modal = '';
        $this->verifyingId = null;
        $this->refusingPriority = false;
        $this->priorityRefusalReason = '';
        $this->priorityNote = '';
        unset($this->outcomes['verify']);
    }

    public function verifyPriority(): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        if (! $this->verifyingId) {
            return;
        }

        $appointment = $this->findTicket($this->verifyingId);

        if (! $appointment) {
            $this->verifyingId = null;

            return;
        }

        /*
         * Re-checked here, not only in openVerify().
         *
         * verifyingId is a public Livewire property, so the browser can set it to
         * any appointment id in the system with a single request. openVerify()
         * guards the button that normally sets it, which protects nothing: the
         * guard has to be on the action that uses the value.
         *
         * Without this, a staffer pinned to their own counter could grant or void
         * RA 9994 / RA 10754 statutory priority on a ticket belonging to another
         * window - deciding who is served next, in a queue they do not work.
         */
        try {
            $this->guardWindow($appointment);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();
            $this->verifyingId = null;

            return;
        }

        if ($appointment->claimedPriorities() === []) {
            $this->error = 'This client has not claimed any priority category.';

            return;
        }

        $this->validate([
            'priorityNote' => ['nullable', 'string', 'max:500'],
        ]);

        $this->queue()->verifyPriority($appointment, auth()->user(), $this->priorityNote ?: null);

        $this->feedback = 'Priority verified for '.$appointment->queue_number.'. Reordering the queue.';
        $this->error = null;

        /*
         * The panel stays open, showing what was done.
         *
         * It used to close, and the confirmation was a banner at the top of
         * the page that hid itself after eight seconds. On a counter machine,
         * a clerk who has just granted a statutory entitlement needs to see
         * that it was granted without scrolling to look for it.
         */
        $this->outcomes['verify'] = [
            'kind' => 'success',
            'text' => 'Priority verified for '.$appointment->queue_number
                .'. The queue has been reordered, and the client has been told.',
        ];
        $this->refusingPriority = false;
        $this->priorityRefusalReason = '';
    }

    public function refusePriority(): void
    {
        if (! $this->guardTheDayIsOpen()) {
            return;
        }

        if (! $this->verifyingId) {
            return;
        }

        $appointment = $this->findTicket($this->verifyingId);

        if (! $appointment) {
            $this->verifyingId = null;

            return;
        }

        // Same reasoning as verifyPriority(): the id arrives from the browser, so
        // the window it belongs to is checked where the value is used.
        try {
            $this->guardWindow($appointment);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();
            $this->verifyingId = null;

            return;
        }

        $this->validate([
            'priorityRefusalReason' => ['required', 'string', 'max:500'],
        ], [
            'priorityRefusalReason.required' => 'Please say why the claim was refused - the client is told this.',
        ]);

        $this->queue()->refusePriority(
            $appointment,
            auth()->user(),
            $this->priorityRefusalReason,
        );

        $this->feedback = 'Priority claim refused for '.$appointment->queue_number.'.';
        $this->error = null;

        /*
         * The panel stays open, showing what was done and what the client was
         * told - and the claim stays on the ticket, so a client who comes
         * back with the document can be verified again.
         */
        $this->outcomes['verify'] = [
            'kind' => 'refused',
            'text' => 'Claim refused for '.$appointment->queue_number
                .'. The client has been told: '.$this->priorityRefusalReason,
        ];
        $this->refusingPriority = false;
        $this->priorityRefusalReason = '';
    }

    public function dismissMessages(): void
    {
        $this->resetFeedback();
    }

    private function resetFeedback(): void
    {
        $this->feedback = null;
        $this->error = null;
    }

    /**
     * Refuse to act on a ticket that belongs to a different window.
     */
    private function guardWindow(Appointment $appointment): void
    {
        if ($this->window && (int) $appointment->service_window_id !== (int) $this->window->id) {
            throw new InvalidQueueTransitionException('That ticket belongs to a different service window.');
        }
    }

    /**
     * Look up a ticket without the raw 404 that findOrFail would produce
     * inside a Livewire AJAX request; a stale id becomes a friendly message.
     */
    private function findTicket(int $appointmentId): ?Appointment
    {
        $appointment = Appointment::find($appointmentId);

        if (! $appointment) {
            $this->error = 'That ticket no longer exists. It may have been cancelled or rescheduled.';
        }

        return $appointment;
    }

    public function render()
    {
        return view('livewire.staff.queue-console');
    }
}
