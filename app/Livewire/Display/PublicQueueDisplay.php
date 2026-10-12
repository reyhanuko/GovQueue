<?php

namespace App\Livewire\Display;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\ServiceWindow;
use App\Services\QueueService;
use Illuminate\Support\Carbon;
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
        $this->windowCode = $windowCode ?: (ServiceWindow::where('is_active', true)->value('code') ?? '');
        // Reached from the public ?date= query string with no authentication, so
        // an unparseable value falls back to today rather than throwing on the
        // first render of a screen that is supposed to be visible to the whole
        // waiting area.
        $this->queueDate = $this->parseDate($queueDate);
        $this->lastUpdatedAt = now()->toTimeString();
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
        if (! $this->window()) {
            return null;
        }

        return app(QueueService::class)->currentlyServing($this->window(), $this->queueDate);
    }

    /**
     * The next few tickets, in service order.
     */
    #[Computed]
    public function upNext()
    {
        if (! $this->window()) {
            return collect();
        }

        return app(QueueService::class)
            ->waitingFor($this->window(), $this->queueDate)
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
