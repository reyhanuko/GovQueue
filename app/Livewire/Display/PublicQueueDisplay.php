<?php

namespace App\Livewire\Display;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\ServiceWindow;
use App\Services\QueueService;
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

    public function mount(?string $windowCode = null, ?string $queueDate = null): void
    {
        $this->windowCode = $windowCode ?: (ServiceWindow::where('is_active', true)->value('code') ?? '');
        $this->queueDate = $queueDate ?: today()->toDateString();
        $this->lastUpdatedAt = now()->toTimeString();
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
        return ServiceWindow::where('code', $this->windowCode)->first();
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
        $this->queueDate = $date;
        $this->refreshToken++;
    }

    public function render()
    {
        return view('livewire.display.public-queue-display');
    }
}
