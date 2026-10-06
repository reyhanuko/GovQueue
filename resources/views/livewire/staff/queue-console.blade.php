{{-- Live over Reverb, with a 15 second poll as a fallback so the board still
     updates if the socket drops mid-shift. --}}
<div class="space-y-4" wire:poll.15s data-window-code="{{ $windowCode }}">

    {{-- Only appears when a poll/action takes a noticeable moment. --}}
    <p wire:loading.delay.long role="status"
       class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800">
        Syncing&hellip;
    </p>

    {{-- Header: window picker, date, live stats --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="console-window" class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Service window
                    </label>
                    <select id="console-window" wire:model.live="windowCode"
                            @disabled($this->windows->isEmpty())
                            class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-slate-100 disabled:text-slate-400">
                        @if ($this->windows->isEmpty())
                            <option value="" selected disabled>No active windows</option>
                        @else
                            @foreach ($this->windows as $window)
                                <option value="{{ $window->code }}">{{ $window->name }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>

                <div>
                    <label for="console-date" class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Queue date
                    </label>
                    <input id="console-date" type="date" wire:model.live="queueDate"
                           class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                </div>
            </div>

            <dl class="flex flex-wrap gap-4 text-center">
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <dt class="text-xs text-slate-500">Served</dt>
                    <dd class="text-lg font-bold text-slate-900">{{ $this->stats['served'] }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <dt class="text-xs text-slate-500">Waiting</dt>
                    <dd class="text-lg font-bold text-slate-900">{{ $this->stats['waiting'] }}</dd>
                </div>
                <div class="rounded-lg bg-amber-50 px-3 py-2">
                    <dt class="text-xs text-amber-700">Priority</dt>
                    <dd class="text-lg font-bold text-amber-900">{{ $this->stats['priority'] }}</dd>
                </div>
                <div class="rounded-lg bg-rose-50 px-3 py-2">
                    <dt class="text-xs text-rose-700">No-shows</dt>
                    <dd class="text-lg font-bold text-rose-900">{{ $this->stats['no_shows'] }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <dt class="text-xs text-slate-500">Avg service</dt>
                    <dd class="text-lg font-bold text-slate-900">{{ $this->stats['avg_service'] }}m</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Flash messages --}}
    @if ($feedback)
        <div role="status" x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show"
             x-transition.opacity
             class="flex items-start justify-between gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-sm font-medium text-emerald-800">{{ $feedback }}</p>
            <button type="button" wire:click="dismissMessages"
                    class="text-xs font-semibold text-emerald-700 hover:underline">Dismiss</button>
        </div>
    @endif

    @if ($error)
        <div role="alert" x-data="{ show: true }" x-init="setTimeout(() => show = false, 12000)" x-show="show"
             x-transition.opacity
             class="flex items-start justify-between gap-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3">
            <p class="text-sm font-medium text-rose-800">{{ $error }}</p>
            <button type="button" wire:click="dismissMessages"
                    class="text-xs font-semibold text-rose-700 hover:underline">Dismiss</button>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Now serving --}}
        <section class="space-y-4">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Now serving</h2>

            @if ($this->serving)
                <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-5">
                    <p class="text-center text-5xl font-black text-amber-900">
                        {{ $this->serving->queue_number }}
                    </p>
                    <p class="mt-2 text-center font-semibold text-amber-900">
                        {{ $this->serving->user->name }}
                    </p>
                    <p class="mt-1 text-center text-sm text-amber-800">
                        {{ $this->serving->service->name }}
                    </p>

                    @if ($this->serving->hasVerifiedPriority())
                        <p class="mt-2 rounded-lg bg-white/70 px-3 py-1 text-center text-xs font-semibold text-amber-900">
                            Verified: {{ $this->serving->priorityLabels() }}
                        </p>
                    @endif

                    <div class="mt-4 space-y-2">
                        <button type="button" wire:click="openComplete({{ $this->serving->id }})"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700">
                            Complete transaction
                        </button>
                        <button type="button" wire:click="skip({{ $this->serving->id }})"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                class="w-full rounded-lg border border-amber-500 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100">
                            Client not ready
                        </button>
                        <button type="button" wire:click="markNoShow"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                wire:confirm="Mark this client as a no-show?"
                                class="w-full rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            Mark no-show
                        </button>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
                    <p class="text-sm text-slate-500">Nobody at the window.</p>
                    <button type="button" wire:click="callNext"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                            class="mt-3 w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">
                        Call next client
                    </button>
                </div>
            @endif

            {{-- Completion panel --}}
            @if ($completingId)
                <div class="rounded-xl border border-slate-300 bg-white p-5 shadow-sm">
                    <h3 class="font-bold text-slate-900">Record the transaction</h3>

                    <label for="fee" class="mt-3 block text-sm font-medium text-slate-700">Fee paid</label>
                    <input id="fee" type="number" step="0.01" min="0" wire:model="feePaid"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('feePaid')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    <label for="tx-note" class="mt-3 block text-sm font-medium text-slate-700">Note</label>
                    <textarea id="tx-note" rows="2" wire:model="transactionNote"
                              placeholder="What was issued, reference numbers, remarks"
                              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
                    @error('transactionNote')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    @include('partials.unsaved-changes')

                    <div class="mt-4 flex gap-2">
                        <button type="button" wire:click="complete"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                class="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Save and close
                        </button>
                        <button type="button" wire:click="$set('completingId', null)"
                                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </section>

        {{-- Waiting line --}}
        <section class="space-y-4 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">
                    Waiting &mdash; {{ $this->waiting->count() }}
                </h2>
                @if ($this->serving)
                    <button type="button" wire:click="callNext"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Call next anyway
                    </button>
                @endif
            </div>

            @if ($this->waiting->isEmpty())
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
                    <p class="text-sm text-slate-500">Nobody has checked in at this window yet.</p>
                </div>
            @else
                <ol class="space-y-2">
                    @php $position = 0; @endphp
                    @foreach ($this->waiting as $appointment)
                        @php
                            $position++;
                            $waitMinutes = $this->estimates[$appointment->id] ?? null;
                        @endphp
                        <li @class([
                            'rounded-xl border bg-white p-4',
                            'border-amber-300 ring-1 ring-amber-300' => $position === 1,
                            'border-slate-200' => $position !== 1,
                        ])>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex items-start gap-3">
                                    <span @class([
                                        'flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-base font-black',
                                        'bg-amber-100 text-amber-900' => $position === 1,
                                        'bg-slate-100 text-slate-700' => $position !== 1,
                                    ])>
                                        {{ $appointment->queue_number }}
                                    </span>

                                    <div>
                                        <p class="font-semibold text-slate-900">{{ $appointment->user->name }}</p>
                                        <p class="text-sm text-slate-600">{{ $appointment->service->name }}</p>
                                        <p class="text-xs text-slate-400">
                                            In at {{ $appointment->checked_in_at?->format('g:i A') ?? 'not recorded' }}
                                            @if ($appointment->skip_count > 0)
                                                &middot; skipped {{ $appointment->skip_count }}&times;
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-1">
                                    @if ($appointment->hasVerifiedPriority())
                                        {{-- Wording matters: staff must be able to tell a
                                             verified statutory claim from an unverified one
                                             at a glance, because only the former reorders
                                             the queue. --}}
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">
                                            &#10003; Verified: {{ $appointment->priorityLabels() }}
                                        </span>
                                    @elseif ($appointment->claimedPriorities() !== [])
                                        <button type="button" wire:click="openVerify({{ $appointment->id }})"
                                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                                class="rounded-full border border-amber-400 bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-800 hover:bg-amber-100">
                                            Verify claim
                                        </button>
                                    @else
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                                            Regular
                                        </span>
                                    @endif

                                    <span class="text-xs text-slate-500">
                                        @if ($waitMinutes === 0)
                                            next
                                        @elseif ($waitMinutes !== null)
                                            ~{{ $waitMinutes }} min wait
                                        @endif
                                    </span>
                                </div>
                            </div>

                            @if ($position === 1)
                                <button type="button" wire:click="skip({{ $appointment->id }})"
                                        wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                        class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Not ready &mdash; move back
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            {{-- Priority verification panel --}}
            @if ($this->verifying)
                <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-5">
                    <h3 class="font-bold text-amber-900">
                        Verify priority &mdash; {{ $this->verifying->queue_number }}
                    </h3>

                    <p class="mt-1 text-sm text-amber-800">
                        {{ $this->verifying->user->name }} claims
                        <strong>{{ $this->verifying->priorityLabels() }}</strong>
                        ({{ $this->verifying->user->barangay }},
                        born {{ $this->verifying->user->birthdate?->format('M d, Y') ?? 'not recorded' }}).
                    </p>

                    <p class="mt-2 rounded-lg bg-white/70 px-3 py-2 text-xs text-amber-900">
                        GovQueue does not verify eligibility against any external database. This
                        decision is yours, made against the document the client presents.
                    </p>

                    <label for="priority-note" class="mt-3 block text-sm font-medium text-amber-900">
                        Reference / reason
                    </label>
                    <input id="priority-note" type="text" wire:model="priorityNote"
                           placeholder="e.g. PWD-1234567, or the reason for refusal"
                           class="mt-1 w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500">
                    @error('priorityNote')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    @include('partials.unsaved-changes')

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="verifyPriority"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-bold text-white hover:bg-amber-700">
                            Verify and prioritise
                        </button>
                        <button type="button" wire:click="refusePriority"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                wire:confirm="Refuse this priority claim?"
                                class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            Refuse claim
                        </button>
                        <button type="button" wire:click="$set('verifyingId', null)"
                                class="rounded-lg border border-amber-300 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100">
                            Close
                        </button>
                    </div>
                </div>
            @endif

            {{-- Expected but not arrived --}}
            <h2 class="pt-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                Booked but not yet checked in
            </h2>

            @if ($this->expected->isEmpty())
                <p class="rounded-xl border border-dashed border-slate-300 bg-white p-5 text-center text-sm text-slate-500">
                    No further bookings expected at this window.
                </p>
            @else
                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    @foreach ($this->expected as $appointment)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-800">{{ $appointment->user->name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $appointment->service->name }}
                                    &middot; booked for {{ $appointment->scheduledTimeLabel() }}
                                    @if ($appointment->queue_number)
                                        &middot; {{ $appointment->queue_number }}
                                    @endif
                                </p>
                                @if ($appointment->claimedPriorities() !== [])
                                    <p class="mt-1 text-xs font-medium text-amber-700">
                                        Claimed: {{ $appointment->priorityLabels() }}
                                        @if ($appointment->priority_reference)
                                            <span class="font-mono">({{ $appointment->priority_reference }})</span>
                                        @endif
                                    </p>
                                @endif
                            </div>

                            <button type="button" wire:click="checkIn({{ $appointment->id }})"
                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                    class="rounded-lg border border-blue-300 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                                Check in
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>