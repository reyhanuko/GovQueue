<?php

namespace App\Livewire\Display;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\ServiceWindow;
use App\Services\QueueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The lobby digital queue display.
 *
 * Unauthenticated by design: it lives on a screen in the waiting area and shows
 * queue numbers and order only. Client names, service names, and priority claims
 * are deliberately never rendered here.
 *
 * Updates arrive by WebSocket (queue.updated) with a slow poll as a fallback, so
 * the board still works if the socket is unavailable.
 */
class PublicQueueDisplay extends Component
{
    public string $windowCode = '';

    public string $queueDate = '';

    /** Bumped on every broadcast so the view knows to re-query. */
    public int $refreshToken = 0;

    public ?string $lastUpdatedAt = null;

    /**
     * Bumped on every render, so the poll fallback always produces a changed
     * payload.
     *
     * Livewire skips re-rendering when a poll returns no changed properties. The
     * board's queries are the same on every poll, so without this bump the 10s
     * poll would do real database work and then return nothing - and the
     * "last updated" stamp would go stale exactly when the socket is down and
     * the stamp matters most.
     */
    public int $pollTick = 0;

    public function mount(?string $windowCode = null, ?string $queueDate = null): void
    {
        /*
         * No window means every window, not the first one.
         *
         * This defaulted to the first active window, so a lobby screen showed one
         * counter's numbers with no indication that others existed. A client
         * waiting for Window 2 had no way to know where to look.
         *
         * The all-windows board is now the default, and ?w=CODE narrows it to one
         * - which is what a lobby running several screens wants, and what the
         * single-window layout still suits.
         *
         * A code that is supplied but does not resolve is kept, so the board says
         * the window is unavailable rather than quietly showing every window as
         * though the mistyped URL had been understood.
         */
        $this->windowCode = trim((string) ($windowCode ?? ''));

        // Reached from the public ?date= query string with no authentication, so
        // an unparseable value falls back to today rather than throwing on the
        // first render of a screen that is supposed to be visible to the whole
        // waiting area.
        $this->queueDate = $this->parseDate($queueDate);
        $this->lastUpdatedAt = now()->toTimeString();
    }

    /**
     * The window codes this board listens to, for the websocket binding.
     *
     * One code on the single-window board, every active window on the all-window
     * board. The lobby display binds an Echo channel per entry, because each
     * window broadcasts separately.
     */
    #[Computed]
    public function subscribedCodes(): array
    {
        if (! $this->showsAllWindows) {
            return [$this->windowCode];
        }

        return $this->windows->pluck('code')->all();
    }

    /** True when the board should show every active window rather than one. */
    #[Computed]
    public function showsAllWindows(): bool
    {
        return $this->windowCode === '';
    }

    /**
     * Every active window with its serving ticket and next few waiting.
     *
     * Queue numbers only, exactly as the single-window view. Client names,
     * services and priority claims are never loaded here - not filtered out at
     * render time - because this is an unauthenticated screen in a public lobby.
     *
     * @return Collection<int, array{window: ServiceWindow, serving: ?Appointment, upNext: Collection}>
     */
    #[Computed]
    public function windowRows()
    {
        $queue = app(QueueService::class);
        $limit = (int) config('govqueue.queue_display_rows');

        return $this->windows
            ->map(fn (ServiceWindow $window) => [
                'window' => $window,
                'serving' => $queue->currentlyServing($window, $this->queueDate),
                'upNext' => $queue->waitingFor($window, $this->queueDate)->take($limit)->values(),
            ])
            ->values();
    }

    /** A usable date string, or today's. Never throws. */
    private function parseDate(?string $date): string
    {
        if (blank($date)) {
            return today()->toDateString();
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
    }

    /**
     * The size classes for the "now serving" number.
     *
     * A queue number is PREFIX-NNN: the prefix is up to four characters and the
     * number is three digits, so a queue number is five to eight characters.
     * A-001 is five. A window whose prefix is CLEA issues CLEA-001, which is
     * eight.
     *
     * Drawn at one size, the long ones do not fit. The all-windows board puts
     * up to three windows side by side, and a number that spills out of its
     * column is a number half the lobby cannot read. A phone is narrower still.
     *
     * So the size follows the length of the number. The board decides how large
     * the largest size is - the single-window board has the whole width to
     * itself - but the rule is the same on both, so a number that is legible on
     * one is legible on the other.
     */
    public function servingNumberClasses(?string $number): string
    {
        return match ($this->numberLength($number)) {
            0, 1, 2, 3, 4, 5 => $this->showsAllWindows ? 'text-6xl' : 'text-7xl sm:text-8xl',
            6, 7 => $this->showsAllWindows ? 'text-5xl' : 'text-6xl sm:text-7xl',
            default => $this->showsAllWindows ? 'text-4xl' : 'text-5xl sm:text-6xl',
        };
    }

    /**
     * The size classes for a number in the "next in line" list.
     *
     * Smaller than the serving number, because it is one of several and the
     * serving number is the one people are watching for. Scaled by length for
     * the same reason, and the two boards differ for the same reason they do
     * above: the single-window board lays the list out as a grid of cards, and
     * a long number at full size does not fit in a card.
     */
    public function upNextNumberClasses(?string $number): string
    {
        return match ($this->numberLength($number)) {
            0, 1, 2, 3, 4, 5 => $this->showsAllWindows ? 'text-2xl' : 'text-4xl',
            6, 7 => $this->showsAllWindows ? 'text-xl' : 'text-3xl',
            default => $this->showsAllWindows ? 'text-lg' : 'text-2xl',
        };
    }

    /**
     * How many characters the number is.
     *
     * A missing number is length zero, and gets the largest size: an empty
     * placeholder cannot overflow anything.
     */
    private function numberLength(?string $number): int
    {
        return strlen((string) $number);
    }

    /**
     * Called by the Echo listener when the window's queue changes.
     *
     * Invoked directly from resources/js/govqueue.js, which binds the
     * queue.updated event and calls this method.
     */
    public function onQueueUpdated(): void
    {
        $this->refreshToken++;
        $this->lastUpdatedAt = now()->toTimeString();
    }

    #[Computed]
    public function windows()
    {
        return ServiceWindow::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function window(): ?ServiceWindow
    {
        return ServiceWindow::where('is_active', true)
            ->where('code', $this->windowCode)
            ->first();
    }

    #[Computed]
    public function serving(): ?Appointment
    {
        if (! $this->window) {
            return null;
        }

        return app(QueueService::class)->currentlyServing($this->window, $this->queueDate);
    }

    /**
     * The next few tickets, in service order.
     */
    #[Computed]
    public function upNext()
    {
        if (! $this->window) {
            return collect();
        }

        return app(QueueService::class)
            ->waitingFor($this->window, $this->queueDate)
            ->take((int) config('govqueue.queue_display_rows'));
    }

    #[Computed]
    public function isClosed(): bool
    {
        return Holiday::isClosedOn($this->queueDate);
    }

    public function setDate(string $date): void
    {
        // Validated here rather than downstream. This is a public Livewire action
        // on an unauthenticated component that re-runs on every 10s poll, so an
        // unparseable value would have thrown on every tick rather than once -
        // and the caller is a URL query string anybody in the lobby can edit.
        $validated = validator(
            ['date' => $date],
            ['date' => ['nullable', 'date']],
        )->validate();

        $this->queueDate = $this->parseDate($validated['date'] ?? null);
        $this->refreshToken++;
    }

    /**
     * Stamps the board and forces a re-render on every poll.
     *
     * The bump on pollTick is what makes the 10s fallback actually redraw: the
     * queue queries return identical results on most polls, and Livewire treats
     * an unchanged payload as "nothing to send".
     */
    public function render()
    {
        $this->pollTick++;
        $this->lastUpdatedAt = now()->toTimeString();

        return view('livewire.display.public-queue-display');
    }
}
