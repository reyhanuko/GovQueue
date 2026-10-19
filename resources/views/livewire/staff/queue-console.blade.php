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
        {{-- Walk-in panel. Placed above the stats because issuing a ticket is
             the other thing an operator does at a window, and it was previously
             not possible at all. --}}
        @if ($walkInOpen)
            <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-5">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="font-bold text-blue-900">Issue a walk-in ticket</h2>
                    <button type="button" wire:click="cancelWalkIn"
                            class="text-xs font-semibold text-blue-800 hover:underline">Close</button>
                </div>

                <form wire:submit="createWalkIn" class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-1">
                        <label for="walkin-service" class="block text-xs font-semibold uppercase tracking-wide text-blue-800">
                            Service
                        </label>
                        <select id="walkin-service" wire:model.live="walkInServiceId"
                                class="mt-1 w-full rounded-lg border border-blue-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @forelse ($this->walkInServices as $available)
                                <option value="{{ $available->id }}">{{ $available->name }}</option>
                            @empty
                                <option value="" disabled>No active services at this window</option>
                            @endforelse
                        </select>
                    </div>

                    <div class="sm:col-span-1">
                        <label for="walkin-name" class="block text-xs font-semibold uppercase tracking-wide text-blue-800">
                            Full name <span class="text-rose-600">*</span>
                        </label>
                        <input id="walkin-name" type="text" required wire:model="walkInName"
                               autocomplete="off"
                               class="mt-1 w-full rounded-lg border border-blue-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('walkInName')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="sm:col-span-1">
                        <label for="walkin-phone" class="block text-xs font-semibold uppercase tracking-wide text-blue-800">
                            Mobile number <span class="font-normal text-blue-700">(optional)</span>
                        </label>
                        <input id="walkin-phone" type="tel" wire:model="walkInPhone"
                               autocomplete="off"
                               class="mt-1 w-full rounded-lg border border-blue-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-blue-800">
                            Used to match an existing account and to text them when called.
                        </p>
                        @error('walkInPhone')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- The duplicate guard. Shown only once BookingService has
                         flagged a match, and it names the ticket already waiting
                         so staff can look for them rather than guess. --}}
                    @if ($this->walkInDuplicate)
                        <div class="sm:col-span-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
                            <p class="text-sm font-semibold text-amber-900">
                                {{ $this->walkInDuplicate->queue_number }} &mdash;
                                {{ $this->walkInDuplicate->user->name }} is already waiting for this service
                            </p>
                            <p class="mt-1 text-xs text-amber-800">
                                Joined {{ $this->walkInDuplicate->checked_in_at?->format('g:i A') }}.
                                Check whether this is the same person before issuing a second ticket.
                            </p>

                            <label class="mt-3 flex items-start gap-2 text-sm text-amber-900">
                                <input type="checkbox" wire:model="walkInOverride"
                                       class="mt-0.5 rounded border-amber-400 text-blue-700 focus:ring-blue-500">
                                <span>
                                    This is a different person &mdash; issue the ticket anyway
                                    <span class="block text-xs text-amber-700">
                                        Recorded in the audit trail as a confirmed duplicate.
                                    </span>
                                </span>
                            </label>
                        </div>
                    @endif

                    {{--
                        Priority claimed at the counter.

                        Previously a walk-in had no way to be recognised as a
                        senior citizen or a PWD client at all - there was no
                        account to carry a claim, so RA 9994 did not reach the
                        people most likely to need it.

                        Recorded as a claim only. The ticket is issued in normal
                        queue order and the claim has to be verified afterwards,
                        exactly like a client's own claim, which is why the
                        wording here says "recorded" and not "priority granted".
                    --}}
                    <fieldset class="sm:col-span-3 rounded-lg border border-blue-200 bg-white p-3">
                        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-blue-800">
                            Does this client claim priority?
                        </legend>

                        <p class="text-xs text-blue-800">
                            RA 9994 (Senior Citizen), RA 10754 (Person with Disability), or the office's
                            priority for pregnant clients. Recording a claim does not move anybody
                            &mdash; you verify it afterwards, and the queue reorders then.
                        </p>

                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            @foreach (\App\Enums\PriorityType::cases() as $type)
                                <label class="flex items-start gap-2 text-sm text-blue-900">
                                    <input type="checkbox" value="{{ $type->value }}"
                                           wire:model.live="walkInClaims"
                                           class="mt-0.5 rounded border-blue-300 text-blue-700 focus:ring-blue-500">
                                    <span>
                                        {{ $type->label() }}
                                        <span class="block text-xs text-blue-700">{{ $type->basis() }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @php
                            $needsReference = array_intersect(
                                [\App\Enums\PriorityType::Pwd->value, \App\Enums\PriorityType::Pregnant->value],
                                $walkInClaims,
                            ) !== [];
                        @endphp

                        @if ($needsReference)
                            <div class="mt-3">
                                <label for="walkin-priority-ref"
                                       class="block text-xs font-semibold uppercase tracking-wide text-blue-800">
                                    Reference <span class="text-rose-600">*</span>
                                </label>
                                <input id="walkin-priority-ref" type="text" wire:model.live.debounce.400s="walkInPriorityReference"
                                       placeholder="PWD ID, medical certificate, or referral"
                                       class="mt-1 w-full rounded-lg border border-blue-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <p class="mt-1 text-xs text-blue-800">
                                    These two categories cannot be inferred from anything at the counter, so a
                                    reference is kept on the ticket.
                                </p>
                            </div>
                        @endif
                    </fieldset>

                    <div class="sm:col-span-3">
                        <button type="submit"
                                wire:loading.attr="disabled"
                                class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-60">
                            Issue ticket
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="console-window" class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Service window
                    </label>

                    @if ($this->canSwitchWindow)
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
                        <p class="mt-1 text-xs text-slate-400">Administrators can open any window.</p>
                    @else
                        {{-- A staffer works their own counter. Not a disabled
                             dropdown pretending to be a choice: a plain label,
                             because there is no choice to make. --}}
                        <p id="console-window"
                           class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700">
                            {{ $this->assignedWindow?->name ?? 'No window assigned' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Your console is limited to your assigned window. An administrator can move you.
                        </p>
                    @endif
                </div>

                <div>
                    <label for="console-date" class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Queue date
                    </label>
                    <input id="console-date" type="date" wire:model.live="queueDate"
                           class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                </div>

                {{-- Walk-in is issued against today's queue, so offering the
                     button while viewing another date would quietly put the
                     ticket in the wrong day's queue. --}}
                @if ($queueDate === today()->toDateString())
                    <button type="button" wire:click="openWalkIn"
                            wire:loading.attr="disabled"
                            class="rounded-lg border border-blue-300 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-100">
                        Walk-in
                    </button>
                @endif
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

            {{-- The shortcuts, where a clerk can find them.

                 A shortcut nobody knows about is not a shortcut. Kept in a
                 disclosure rather than shown outright, because a clerk working
                 a queue does not need a keyboard legend in front of them all
                 shift, but a clerk learning the console does. --}}
            <details class="mt-3 text-xs text-slate-500">
                <summary class="cursor-pointer font-semibold text-slate-600 hover:text-slate-800">
                    Keyboard shortcuts
                </summary>
                <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1">
                    <div class="flex gap-2">
                        <dt>Call next client:</dt>
                        <dd class="font-mono font-semibold text-slate-700">Alt+N</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt>Complete transaction:</dt>
                        <dd class="font-mono font-semibold text-slate-700">Alt+C</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt>Client not ready:</dt>
                        <dd class="font-mono font-semibold text-slate-700">Alt+S</dd>
                    </div>
                </dl>
            </details>
        </div>
    </div>

    {{-- Flash messages

         The auto-hide leaves a message alone while it holds the focus.

         A Livewire action moves the focus to the message that reports what it
         did, so that a keyboard user is told rather than left pressing the
         same button again. But these messages hide themselves after a few
         seconds, and a message that is removed while it holds the focus takes
         the focus with it - focus falls to the body, and the next Tab starts
         from the top of the page. Which is the problem this was fixing.

         So a message that has the focus stays until it is dismissed. --}}
    @if ($feedback)
        <div role="status" x-data="{ show: true }"
             x-init="setTimeout(() => { if (! $el.contains(document.activeElement)) show = false }, 8000)"
             x-show="show"
             x-transition.opacity
             class="flex items-start justify-between gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-sm font-medium text-emerald-800">{{ $feedback }}</p>
            <button type="button" wire:click="dismissMessages"
                    class="text-xs font-semibold text-emerald-700 hover:underline">Dismiss</button>
        </div>
    @endif

    @if ($error)
        <div role="alert" x-data="{ show: true }"
             x-init="setTimeout(() => { if (! $el.contains(document.activeElement)) show = false }, 12000)"
             x-show="show"
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
                                data-gq-shortcut="complete"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                wire:target="openComplete"
                                class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 transition-all">
                            <span wire:loading.remove wire:target="openComplete">Complete transaction</span>
                            <span wire:loading wire:target="openComplete" class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </button>
                        <button type="button" wire:click="skip({{ $this->serving->id }})"
                                data-gq-shortcut="skip"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                wire:target="skip"
                                class="w-full rounded-lg border border-amber-500 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition-all">
                            <span wire:loading.remove wire:target="skip">Client not ready</span>
                            <span wire:loading wire:target="skip">Skipping...</span>
                        </button>
                        <button type="button" wire:click="markNoShow"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                wire:target="markNoShow"
                                wire:confirm="Mark this client as a no-show?"
                                class="w-full rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 transition-all">
                            <span wire:loading.remove wire:target="markNoShow">Mark no-show</span>
                            <span wire:loading wire:target="markNoShow">Processing...</span>
                        </button>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
                    <p class="text-sm text-slate-500">Nobody at the window.</p>
                    <button type="button" wire:click="callNext"
                            data-gq-shortcut="call-next"
                            wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                            wire:target="callNext"
                            class="mt-3 w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all">
                        <span wire:loading.remove wire:target="callNext">Call next client</span>
                        <span wire:loading wire:target="callNext" class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Calling...
                        </span>
                    </button>
                </div>
            @endif

            {{-- Completion panel --}}
            @if ($completingId)
                <div class="rounded-xl border border-slate-300 bg-white p-5 shadow-sm">
                    <h3 class="font-bold text-slate-900">Record the transaction</h3>

                    {{-- A service with no configured fee must not look like a failed prefill.
                         "0.00" on a free service and "0.00" on a ₱50 service are
                         indistinguishable in a number box, so the free case says
                         so in words and the input is not editable. The standard
                         fee comes from the ticket being completed, not from
                         whoever happens to be at the window. --}}
                    @php $standardFee = (float) ($this->completingServiceFee ?? 0); @endphp

                    <label for="fee" class="mt-3 block text-sm font-medium text-slate-700">Fee paid</label>

                    @if ($standardFee <= 0)
                        <input id="fee" type="text" value="No fee for this service" disabled
                               class="mt-1 w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                        <p class="mt-1 text-xs text-slate-500">
                            This service has no fee configured, so nothing is collected at the counter.
                        </p>
                        <input type="hidden" wire:model="feePaid" value="0">
                    @else
                        <input id="fee" type="number" step="0.01" min="0" wire:model="feePaid"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">

                        {{-- Staff should not silently charge a different amount, and
                             should not be blocked from recording what was actually
                             paid (partials, exemptions). This warns, it does not block. --}}
                        <div wire:dirty.live="feePaid" x-data="{}"
                             class="mt-1 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            <p class="font-semibold">Fee changed from the standard &#8369;{{ number_format($standardFee, 2) }}</p>
                            <p class="mt-0.5 text-xs">
                                This service is set at &#8369;{{ number_format($standardFee, 2) }}. Recording a different amount is
                                allowed &mdash; for a partial payment or an approved exemption &mdash; and the amount you enter is
                                what appears in Reports &rarr; Fees collected.
                            </p>
                        </div>

                        @if ($standardFee > 0 && (float) $feePaid > 0 && abs((float) $feePaid - $standardFee) > 0.009)
                            <p class="mt-1 text-xs font-medium text-amber-700">
                                Entering {{ number_format((float) $feePaid, 2) }} instead of
                                {{ number_format($standardFee, 2) }}.
                            </p>
                        @endif
                    @endif

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

        {{--
            Recently lost. Cancellations and no-shows leave no trace on the
            waiting line above - a cancelled ticket simply is not there - so
            without this a freed slot, or a client who never arrived, is
            invisible from the counter.
        --}}
        @if ($this->recentlyLost->isNotEmpty())
            <section class="lg:col-span-2" aria-labelledby="recently-lost">
                <h2 id="recently-lost" class="text-sm font-bold uppercase tracking-wide text-slate-500">
                    Cancelled &amp; no-show &mdash; {{ $this->recentlyLost->count() }}
                </h2>

                <ul class="mt-2 space-y-2">
                    @foreach ($this->recentlyLost as $lost)
                        <li @class([
                            'flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-white p-3',
                            'border-slate-300' => $lost->status === \App\Enums\AppointmentStatus::NoShow,
                            'border-rose-200' => $lost->status !== \App\Enums\AppointmentStatus::NoShow,
                        ])>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900">
                                    {{ $lost->user?->name ?? 'Unknown client' }}
                                    <span class="ml-1 font-normal text-slate-500">
                                        {{ $lost->scheduledTimeLabel() }}
                                    </span>
                                    <span class="ml-1 font-mono text-xs text-slate-400">{{ $lost->reference_number }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $lost->service?->name ?? '—' }}
                                    &middot;
                                    {{ $lost->status === \App\Enums\AppointmentStatus::NoShow ? 'Did not arrive' : 'Cancelled' }}
                                    {{ $lost->updated_at ? $lost->updated_at->diffForHumans() : '' }}
                                </p>
                            </div>

                            <span class="shrink-0 text-xs font-medium text-slate-500">Slot is free</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Waiting line --}}
        <section class="space-y-4 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">
                    Waiting &mdash; {{ $this->waiting->count() }}
                </h2>
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

                                        {{-- A client who was moved back and has come
                                             back to the counter should not have to
                                             wait for the queue order to reach them. --}}
                                        @if ($appointment->status === \App\Enums\AppointmentStatus::Skipped)
                                            <button type="button"
                                                    wire:click="callBack({{ $appointment->id }})"
                                                    wire:loading.attr="disabled" wire:target="callBack({{ $appointment->id }})"
                                                    class="mt-2 rounded-lg border border-blue-300 bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-100 disabled:opacity-60">
                                                They're here &mdash; call back
                                            </button>
                                        @endif
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