{{-- Client's live ticket view. Private channel, so only this client sees it. --}}
<div class="space-y-4" wire:poll.20s data-appointment-id="{{ $appointmentId }}">
    @php $appointment = $this->appointment; $statusEnum = $this->statusEnum; @endphp

    @if ($fetchError)
        <div role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="font-medium">{{ $fetchError }}</p>
                </div>
                <button wire:click="refreshPosition" 
                        type="button"
                        class="flex-shrink-0 rounded-lg bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-200 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition-colors"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-wait">
                    <span wire:loading.remove wire:target="refreshPosition">Try again</span>
                    <span wire:loading wire:target="refreshPosition">Retrying...</span>
                </button>
            </div>
        </div>
    @endif

    @if (! $appointment)
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">
            This appointment could not be found.
        </div>
    @else
        <div @class([
                'rounded-xl border bg-white p-6 transition',
                'border-blue-400 ring-2 ring-blue-200' => $justUpdated,
                'border-slate-200' => ! $justUpdated,
            ])>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Your queue number
                    </p>
                    <p class="mt-1 text-4xl font-black text-blue-800">
                        {{ $queueNumber ?? 'Assigned at the counter' }}
                    </p>
                    <p class="mt-1 font-mono text-xs text-slate-500">
                        {{ $appointment->reference_number }}
                    </p>
                </div>

                <div class="text-right">
                    @if ($statusEnum)
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $statusEnum->badgeClasses() }}">
                            {{ $statusEnum->label() }}
                        </span>
                    @endif
                    <p class="mt-1 text-xs text-slate-500" aria-live="polite">
                        @if ($this->waitLabel)
                            {{ $this->waitLabel }}
                        @endif
                    </p>
                </div>
            </div>

            @if ($position !== null)
                <div class="mt-5">
                    <div class="flex items-center justify-between text-xs font-medium text-slate-600">
                        <span>Your place in the queue</span>
                        <span>
                            @if ($position === 1)
                                Next
                            @elseif ($this->queueTotal > 0)
                                {{ $position }} of {{ $this->queueTotal }}
                            @endif
                        </span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                        {{-- Bar shortens as the client nears the window. --}}
                        @php $progress = $position <= 1 ? 100 : max(8, 100 - min(92, ($position - 1) * 12)); @endphp
                        <div class="h-full rounded-full bg-blue-600 transition-all duration-500"
                             style="width: {{ $progress }}%"></div>
                    </div>
                </div>
            @endif

            @if ($statusEnum === \App\Enums\AppointmentStatus::Serving)
                <p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                    Your number has been called. Please proceed to
                    {{ $appointment->window?->name ?? 'the service window' }}.
                </p>
            @elseif ($statusEnum === \App\Enums\AppointmentStatus::CheckedIn)
                <p class="mt-4 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    You are checked in. Stay nearby &mdash; this page updates when your number is called.
                </p>
            @elseif ($statusEnum === \App\Enums\AppointmentStatus::Confirmed)
                <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Booked for {{ $appointment->scheduledTimeLabel() }} on
                    {{ $appointment->scheduledDateLabel() }}. Check in at the counter when you arrive.
                </p>
            @endif

            @if ($appointment->claimedPriorities() !== [])
                <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    @if ($appointment->hasVerifiedPriority())
                        <strong>Priority verified:</strong> {{ $appointment->verifiedPriorityLabels() }}.
                    @elseif ($appointment->hasRefusedPriority())
                        <strong>Priority refused:</strong> {{ $appointment->claimedPriorityLabels() }}.
                        Staff could not accept the claim on
                        {{ $appointment->priority_refused_at->format('M d') }}:
                        {{ $appointment->priority_refused_reason }}
                    @else
                        <strong>Priority claimed:</strong> {{ $appointment->claimedPriorityLabels() }}.
                        It applies once staff verify your claim at the counter.
                    @endif
                </p>
            @endif

            <p class="mt-4 text-xs text-slate-400">
                Estimated waiting times are approximate and based on recent service times.
            </p>
        </div>
    @endif
</div>