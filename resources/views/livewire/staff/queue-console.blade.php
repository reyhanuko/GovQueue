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
                                {{-- One reference per claim, as for a client's own
                                     claim. A PWD ID and a medical certificate are
                                     different documents, and the staff have to be
                                     able to tell which number belongs to
                                     which claim.

                                     Shown for the categories actually claimed:
                                     the panel is server-rendered, so a
                                     reference is only asked for when it is
                                     wanted. --}}
                                @foreach (\App\Enums\PriorityType::requiringReference() as $type)
                                    @if (in_array($type->value, $walkInClaims, true))
                                        <div wire:key="walkin-ref-{{ $type->value }}">
                                            <label for="walkin-ref-{{ $type->value }}"
                                                   class="block text-xs font-semibold uppercase tracking-wide text-blue-800">
                                                {{ $type->shortLabel() }} reference <span class="text-rose-600">*</span>
                                            </label>
                                            <input id="walkin-ref-{{ $type->value }}" type="text"
                                                   wire:model.live.debounce.400s="walkInReferences.{{ $type->value }}"
                                                   placeholder="{{ $type->value === 'pwd' ? 'PWD ID number' : 'Medical certificate or referral number' }}"
                                                   class="mt-1 w-full rounded-lg border border-blue-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                            @error('walkInReferences.'.$type->value)
                                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @endif
                                @endforeach
                                <p class="mt-2 text-xs text-blue-800">
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
                            Your console is limited to your assigned window. To work another
                            one, an administrator has to reassign you.
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
                {{-- py-1.5 so the summary is a target of its own: at text-xs it
                     is 16px tall, which is under the 24px that WCAG asks for,
                     and a disclosure a clerk has to aim at is a disclosure that
                     does not get opened. --}}
                <summary class="cursor-pointer py-1.5 font-semibold text-slate-600 hover:text-slate-800">
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

    {{--
        What day is this, and what can be done with it.

        The date field reaches any day, and every queue action acts on the day
        that is selected. So the operator has to be told which kind of day they
        are looking at before they touch anything: a day that is over is a
        record, and a day that has not come is a booking list. Neither is a
        queue.
    --}}
    @unless ($this->lookingAtToday)
        <div class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-blue-900">
                    @if ($this->selectedDayIsOver)
                        {{ $this->selectedDayLabel }} is over.
                        <span class="font-normal">This is what happened.</span>
                    @else
                        {{ $this->selectedDayLabel }} has not come yet.
                        <span class="font-normal">This is who is booked.</span>
                    @endif
                </p>
                <p class="mt-1 text-sm text-blue-800">
                    @if ($this->selectedDayIsOver)
                        A finished day is a record. It cannot be served, changed, or have
                        somebody added to it, and the figures below are that record.
                    @else
                        There is no queue to work until the day comes. Nobody can be called
                        before the morning they are booked for.
                    @endif
                </p>
            </div>
        </div>
    @endunless

    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Now serving --}}
        @if ($this->lookingAtToday)
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
                            Verified: {{ $this->serving->verifiedPriorityLabels() }}
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

                        {{-- The client was here, but the service could not be given.

                             Not a no-show: they were at the counter. Not a
                             completion: nothing was issued. A third answer,
                             with the reason the client is told. --}}
                        <button type="button" wire:click="openNotComplete({{ $this->serving->id }})"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                wire:target="openNotComplete"
                                class="w-full rounded-lg border border-purple-300 px-4 py-2 text-sm font-semibold text-purple-700 hover:bg-purple-50 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-all">
                            Not completed
                        </button>

                        {{-- No browser confirm() here.

                             A no-show is recorded against a client's record, and
                             for a client who did not come it counts towards
                             the strikes that pause their bookings. The
                             browser's own confirm() says one sentence, offers
                             no room to write down why, and looks like
                             something the page did rather than something the
                             office decided. --}}
                        <button type="button" wire:click="openNoShow({{ $this->serving->id }})"
                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                wire:target="openNoShow"
                                class="w-full rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 transition-all">
                            <span wire:loading.remove wire:target="openNoShow">Mark no-show</span>
                            <span wire:loading wire:target="openNoShow">Processing...</span>
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

                    {{-- The receipt, where the transaction was taken.

                         The panel used to close and put a green banner at the
                         top of the page, which hid itself after eight
                         seconds. A clerk who has just taken a payment needs
                         to see that it was taken, without scrolling to look
                         for it. --}}
                    @if (($outcomes['complete'] ?? null))
                        <div role="status" class="mt-3 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3">
                            <p class="text-sm font-semibold text-emerald-900">
                                &#10003; {{ $outcomes['complete']['text'] }}
                            </p>
                            @if ($feedback)
                                <p class="mt-1 text-xs text-emerald-800">{{ $feedback }}</p>
                            @endif
                            <button type="button" wire:click="$set('completingId', null)"
                                    class="mt-2 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">
                                Done
                            </button>
                        </div>
                    @else

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
                            Save transaction
                        </button>
                        <button type="button" wire:click="$set('completingId', null)"
                                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                    </div>
                    @endif
                </div>
            @endif
        </section>
        @endif

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
            @if ($this->lookingAtToday)
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
                                    {{-- min-w-11 and whitespace-nowrap, not a fixed
                                         w-11: a queue number is five characters at
                                         the least and eight at the most, and in a
                                         44-pixel square the five-character ones
                                         wrapped onto two lines - "A-" above "003",
                                         on the number the clerk is reading. The
                                         badge grows instead. --}}
                                    <span @class([
                                        'flex h-11 min-w-11 shrink-0 items-center justify-center whitespace-nowrap rounded-lg px-2 text-base font-black',
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
                                            &#10003; Verified: {{ $appointment->verifiedPriorityLabels() }}
                                        </span>
                                    @elseif ($appointment->claimedPriorities() !== [])
                                        {{-- The claim is on the ticket, so the clerk is told
                                             which one it is - not just that there is
                                             something to verify. A badge that said only
                                             "Verify claim" left the clerk to open the
                                             panel to find out what was being claimed. --}}
                                        <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-bold text-amber-800">
                                            {{ $appointment->claimedPriorityLabels() }} &mdash;
                                            {{ $appointment->hasRefusedPriority() ? 'refused' : 'awaiting verification' }}
                                        </span>
                                        {{-- A fingertip, not a mouse.

                                             The button was 22 pixels tall, under the
                                             24 that WCAG 2.2 asks for - and it
                                             only appears when a client has a
                                             claim on the ticket, so no browser
                                             test had a reason to be on the
                                             page that shows it. --}}
                                        <button type="button" wire:click="openVerify({{ $appointment->id }})"
                                                wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                                class="inline-flex min-h-[24px] items-center rounded-full border border-amber-400 bg-white px-2.5 py-1 text-xs font-bold text-amber-800 hover:bg-amber-100">
                                            {{ $appointment->hasRefusedPriority() ? 'Decide again' : 'Verify claim' }}
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

            {{--
                Who has been served today.

                The day that is over has a "Served" list, and a day that is
                still running had none: a clerk working the counter could see
                the line, the client at the window and the bookings still to
                come, and not one of the people they had already dealt with
                that morning. So they could not tell who had been served,
                could not see how long each had taken, and had no way to
                answer a client who came back asking whether they had been
                called.

                The list is folded away, because a busy day is a long list and
                the queue is what a clerk is working on. The count is always
                on the summary line, so "how many have I served" is answered
                without opening anything.
            --}}
            @if ($this->lookingAtToday)
                <details class="rounded-xl border border-slate-200 bg-white">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold uppercase tracking-wide text-slate-500">
                        Served today &mdash; {{ $this->servedOnSelectedDay->count() }}
                        <span class="text-xs font-normal normal-case text-slate-400">who has been dealt with</span>
                    </summary>

                    @if ($this->servedOnSelectedDay->isEmpty())
                        <p class="border-t border-slate-100 px-4 py-5 text-center text-sm text-slate-500">
                            Nobody has been served at this window yet today.
                        </p>
                    @else
                        <ol class="divide-y divide-slate-100 border-t border-slate-100">
                            @foreach ($this->servedOnSelectedDay as $appointment)
                                @php $serviceMinutes = $appointment->actualServiceMinutes(); @endphp
                                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-800">
                                            {{ $appointment->user->name }}
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            {{ $appointment->service->name }}
                                            @if ($appointment->serving_started_at)
                                                &middot; from {{ $appointment->serving_started_at->format('g:i A') }}
                                            @endif
                                            @if ($serviceMinutes !== null)
                                                &middot; took {{ $serviceMinutes }} min
                                            @endif
                                            @if ($appointment->queue_number)
                                                &middot; <span class="font-mono">{{ $appointment->queue_number }}</span>
                                            @endif
                                            @if ($appointment->status === \App\Enums\AppointmentStatus::NotCompleted)
                                                &middot; <span class="font-semibold text-purple-700">not completed</span>
                                            @endif
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </details>
            @endif

            @else
                {{--
                    The record of a day that is over.

                    A clerk covering a counter, or writing the day's report, does
                    not want a queue to work - they want to know what happened.
                    So the waiting line is replaced by what it became: who was
                    served and how long each took, and anybody the day lost.

                    A day that has not come is the other case, and it has
                    nothing to report: nobody has been served at a window that
                    has not opened. What it has is a booking list, which is
                    below.
                --}}
                @if ($this->selectedDayIsOver)
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">
                            Served &mdash; {{ $this->servedOnSelectedDay->count() }}
                        </h2>
                    </div>

                @if ($this->servedOnSelectedDay->isEmpty())
                    <p class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                        Nobody was served at this window on this day.
                    </p>
                @else
                    <ol class="space-y-2">
                        @foreach ($this->servedOnSelectedDay as $appointment)
                            @php $serviceMinutes = $appointment->actualServiceMinutes(); @endphp
                            <li class="rounded-xl border border-slate-200 bg-white p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-slate-900">
                                            {{ $appointment->user->name }}
                                        </p>
                                        <p class="mt-0.5 text-sm text-slate-600">
                                            {{ $appointment->service->name }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            @if ($appointment->serving_started_at)
                                                Served from {{ $appointment->serving_started_at->format('g:i A') }}
                                            @endif
                                            @if ($serviceMinutes !== null)
                                                &middot; took {{ $serviceMinutes }} min
                                            @endif
                                            @if ($appointment->queue_number)
                                                &middot; <span class="font-mono">{{ $appointment->queue_number }}</span>
                                            @endif
                                            @if ($appointment->status === \App\Enums\AppointmentStatus::NotCompleted)
                                                &middot; <span class="font-semibold text-purple-700">not completed</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($this->unresolvedOnSelectedDay->isNotEmpty())
                    <div class="flex items-center justify-between">
                        <h2 class="pt-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                            Never resolved &mdash; {{ $this->unresolvedOnSelectedDay->count() }}
                        </h2>
                    </div>

                    <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        These bookings were never finished. The hourly job closes them once their
                        slot has ended, so this list is either recent, or the job could not
                        close them.
                    </p>

                    <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        @foreach ($this->unresolvedOnSelectedDay as $appointment)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-800">
                                        {{ $appointment->user->name }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{ $appointment->service->name }}
                                        @if ($appointment->checked_in_at)
                                            &middot; checked in at {{ $appointment->checked_in_at->format('g:i A') }}
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @endif
            @endif

            {{--
                Expected but not arrived.

                A working list, so it is for the days that have not been and
                gone. On a day that is over, a booking that was never checked
                in is part of the record, and it is in the record already -
                the "never resolved" list above holds every booking the day
                did not finish, whatever stage it had reached.
            --}}
            @unless ($this->selectedDayIsOver)
                <h2 class="pt-2 text-sm font-bold uppercase tracking-wide text-slate-500">
                    Booked but not yet checked in
                    &mdash; {{ $this->expected->count() }}
                </h2>

                @if ($this->expected->isEmpty())
                    <p class="rounded-xl border border-dashed border-slate-300 bg-white p-5 text-center text-sm text-slate-500">
                        No further bookings expected at this window.
                    </p>
                @else
                    <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        @foreach ($this->expected->take(8) as $appointment)
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
                                            Claimed: {{ $appointment->claimedPriorityLabels() }}
                                            @if ($appointment->priority_reference)
                                                <span class="font-mono">({{ $appointment->priority_reference }})</span>
                                            @endif
                                        </p>

                                        {{-- The documents themselves.

                                             A reference number says that a
                                             document exists; it does not let
                                             anybody check that the document
                                             is the one the claim says it is.
                                             The client is not here yet, so
                                             this is the one chance the clerk
                                             has to look before the claim is
                                             decided at the counter - and
                                             looking is not deciding: the
                                             decision is still made with the
                                             client in front of them. --}}
                                        @if ($appointment->priorityDocuments->isNotEmpty())
                                            <ul class="mt-1 space-y-1">
                                                @foreach ($appointment->priorityDocuments as $document)
                                                    <li class="flex flex-wrap items-center gap-2 text-xs font-normal text-slate-600">
                                                        <span class="truncate">{{ $document->displayName() }}</span>
                                                        <span class="text-slate-500">{{ $document->label() }}</span>
                                                        <a href="{{ route('staff.priority-document.show', $document) }}"
                                                           target="_blank" rel="noopener"
                                                           class="shrink-0 rounded border border-amber-400 bg-white px-1.5 py-0.5 font-semibold text-amber-900 hover:bg-amber-100">
                                                            Open
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    @endif
                                </div>

                                {{-- Check in is for the day the client was booked
                                     for, and only in the window around their slot.
                                     Offering it for another day would offer a
                                     button that cannot work, and offering it
                                     outside the window would offer a button
                                     that is refused when it is pressed. --}}
                                @if ($this->lookingAtToday)
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($appointment->isCheckInWindowOpen())
                                            <button type="button" wire:click="checkIn({{ $appointment->id }})"
                                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                                    class="rounded-lg border border-blue-300 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                                                Check in
                                            </button>
                                        @else
                                            @php [$opensAt, $closesAt] = $appointment->checkInWindow(); @endphp
                                            <span class="text-xs text-slate-500">
                                                Check-in opens at {{ $opensAt?->format('g:i A') }}
                                            </span>
                                        @endif

                                        {{-- The client who did not come.

                                             The counter's own no-show button only
                                             reaches the client at the window. A
                                             booked client who never arrived and
                                             whose slot has now passed cannot be
                                             marked by anybody, so the place is
                                             held for somebody who is not there
                                             until the hourly close-out reaches
                                             the ticket.

                                             The button opens the same panel the
                                             counter's own no-show does, rather
                                             than the browser's confirm(): the
                                             clerk is told what will be recorded
                                             before it is recorded.

                                             It appears once the client can no
                                             longer check in - a quarter of an
                                             hour after their slot, because the
                                             check-in window reaches that far
                                             past it. Marking somebody a
                                             no-show while they are still
                                             entitled to check in would be
                                             saying they did not come when
                                             they were on their way, and it
                                             would count against them. --}}
                                        @if ($appointment->scheduled_at && ! $appointment->canStillCheckIn())
                                            <button type="button" wire:click="openNoShow({{ $appointment->id }})"
                                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                                    class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                                Mark no-show
                                            </button>
                                        @endif

                                        {{-- The booking the office will not serve.

                                             A client who cannot be served - the
                                             service cannot be given today, the
                                             office will not serve that
                                             booking, they have asked to come
                                             another day - could only be
                                             recorded as a no-show, which says
                                             they did not come and counts
                                             against them for it.

                                             This is the office's decision, so
                                             it carries no strike, the place
                                             goes back to the pool, and the
                                             client is told what was said and
                                             why. --}}
                                        @if ($appointment->canStillCheckIn())
                                            <button type="button" wire:click="openCancelBooking({{ $appointment->id }})"
                                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                Cancel booking
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->expected->count() > 8)
                        <p class="mt-2 text-xs text-slate-500">
                            Showing the first 8 of
                            {{ $this->expected->count() }} bookings. The rest are further down the day.
                        </p>
                    @endif
                @endif
            @endunless
        </section>
    </div>

    {{--
        The decision dialog.

        The console used to use the browser's own confirm() for the decisions
        that matter: marking a no-show, refusing a statutory claim. That is an
        operating-system window that says one sentence, offers no room to
        write down why, and looks like something the page did rather than
        something the office decided. And the confirmation of a decision was
        a green banner at the top of a long page, which hid itself after
        eight seconds.

        So the decisions are made in a dialog of our own, in the middle of
        the screen, with the reason the client is going to be told written
        into it - and the result is shown where the decision was taken.
    --}}
    @if ($this->modal !== '')
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
             role="dialog"
             aria-modal="true"
             aria-labelledby="console-dialog-title"
             wire:keydown.escape.window="cancelModal">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-2xl">

                @if ($this->modal === 'verify' && $this->verifying)
                    <h3 id="console-dialog-title" class="text-lg font-bold text-amber-900">
                        Priority claim &mdash; {{ $this->verifying->queue_number }}
                    </h3>

                    {{-- What was decided, if anything. --}}
                    @if (($outcomes['verify'] ?? null))
                        <div role="status"
                             class="mt-4 rounded-lg border px-4 py-3
                             {{ ($outcomes['verify']['kind'] ?? '') === 'refused'
                                 ? 'border-rose-300 bg-rose-50'
                                 : 'border-emerald-300 bg-emerald-50' }}">
                            <p class="text-sm font-semibold
                                      {{ ($outcomes['verify']['kind'] ?? '') === 'refused'
                                          ? 'text-rose-900'
                                          : 'text-emerald-900' }}">
                                &#10003; {{ $outcomes['verify']['text'] }}
                            </p>
                            <button type="button" wire:click="cancelModal"
                                    class="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Done
                            </button>
                        </div>
                    @else
                        <p class="mt-1 text-sm text-slate-700">
                            {{ $this->verifying->user->name }} claims
                            <strong>{{ $this->verifying->claimedPriorityLabels() }}</strong>
                            ({{ $this->verifying->user->barangay }},
                            born {{ $this->verifying->user->birthdate?->format('M d, Y') ?? 'not recorded' }}).
                        </p>

                        {{-- A claim that was refused before.

                             The client is told, so the clerk should be too:
                             a client who comes back with the document the
                             office asked for deserves to be recognised. --}}
                        @if ($this->verifying->hasRefusedPriority())
                            <div class="mt-3 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                                <p class="font-semibold">Refused on {{ $this->verifying->priority_refused_at->format('M d, Y g:i A') }}</p>
                                <p class="mt-0.5 text-xs">{{ $this->verifying->priority_refused_reason }}</p>
                                <p class="mt-1 text-xs">
                                    The client was told this. If they have the document now, verifying the
                                    claim will replace it.
                                </p>
                            </div>
                        @endif

                        {{-- The birthdate, and whether it qualifies.

                             The client cannot claim senior priority for themselves:
                             the booking form derives it from the date of birth on
                             their profile. So a senior citizen claim on a ticket
                             whose client is under the RA 9994 threshold means the
                             birthdate on file is wrong - and a wrong birthdate is
                             worth more than the claim it is refusing, because it
                             also decides what else the office may do for them.

                             The point of showing it is that the clerk can see the
                             problem rather than having to remember it. --}}
                        @if ($this->verifying->claims_senior_citizen)
                            @php
                                $threshold = (int) config('govqueue.senior_citizen_age');
                                $birthdate = $this->verifying->user->birthdate;
                                $age = $birthdate?->age;
                            @endphp
                            @if ($age !== null && $age < $threshold)
                                <p class="mt-3 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-900">
                                    This client was born in {{ $birthdate->format('Y') }} and is
                                    {{ $age }} years old, so the date of birth on file does not
                                    meet the RA 9994 threshold of {{ $threshold }}. The claim
                                    cannot be verified on this date of birth.
                                </p>
                            @endif
                        @endif

                        {{-- The soft copy of the document, when the client sent one.

                             A reference number says that a document exists; it does
                             not let anybody check that the document is the one the
                             claim says it is. --}}
                        @if ($this->verifying->priorityDocuments->isNotEmpty())
                            <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                    {{ $this->verifying->priorityDocuments->count() === 1
                                        ? 'Document sent with the claim'
                                        : 'Documents sent with the claim' }}
                                </p>

                                @foreach ($this->verifying->priorityDocuments as $document)
                                    <div class="mt-2 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm text-slate-800">
                                                {{ $document->displayName() }}
                                            </p>
                                            <p class="text-xs text-slate-500">{{ $document->label() }}</p>
                                        </div>
                                        <a href="{{ route('staff.priority-document.show', $document) }}"
                                           target="_blank" rel="noopener"
                                           class="shrink-0 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                                            Open
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            GovQueue does not verify eligibility against any external database. This
                            decision is yours, made against the document the client presents.
                        </p>

                        @if ($this->refusingPriority)
                            {{-- The reason for a refusal.

                                 Required, and the client is told it: a refusal
                                 they cannot read the reason for is a refusal
                                 they cannot act on. --}}
                            <label for="priority-refusal" class="mt-4 block text-sm font-medium text-slate-900">
                                Why the claim is refused <span class="text-rose-600">*</span>
                            </label>
                            <textarea id="priority-refusal" rows="3" wire:model="priorityRefusalReason"
                                      placeholder="e.g. No PWD ID presented. Bring the ID and come back."
                                      class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"></textarea>
                            @error('priorityRefusalReason')
                                <p class="mt-1 text-xs font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-slate-500">
                                The client sees this on their appointment page. They can be verified
                                again if they come back with the document.
                            </p>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" wire:click="refusePriority"
                                        wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">
                                    Refuse the claim
                                </button>
                                <button type="button" wire:click="cancelRefuse"
                                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    Back
                                </button>
                            </div>
                        @else
                            <label for="priority-note" class="mt-4 block text-sm font-medium text-slate-900">
                                Reference checked
                            </label>
                            <input id="priority-note" type="text" wire:model="priorityNote"
                                   placeholder="e.g. PWD-1234567, seen on the ID"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500">
                            @error('priorityNote')
                                <p class="mt-1 text-xs font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-slate-500">
                                What you read off the document. The client sees this on their
                                appointment page.
                            </p>

                            @include('partials.unsaved-changes')

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" wire:click="verifyPriority"
                                        wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                        class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-bold text-white hover:bg-amber-700">
                                    Verify and prioritise
                                </button>
                                <button type="button" wire:click="openRefuse"
                                        class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                    Refuse claim
                                </button>
                                <button type="button" wire:click="cancelModal"
                                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    Close
                                </button>
                            </div>
                        @endif
                    @endif
                @elseif ($this->modal === 'no_show')
                    <h3 id="console-dialog-title" class="text-lg font-bold text-slate-900">
                        Mark as a no-show
                    </h3>

                    @if (($outcomes['no_show'] ?? null))
                        <div role="status" class="mt-4 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3">
                            <p class="text-sm font-semibold text-emerald-900">
                                &#10003; {{ $outcomes['no_show']['text'] }}
                            </p>
                            <button type="button" wire:click="cancelModal"
                                    class="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Done
                            </button>
                        </div>
                    @else
                        @php $noShowTicket = $this->noShowingId ? \App\Models\Appointment::find($this->noShowingId) : null; @endphp

                        <p class="mt-2 text-sm text-slate-700">
                            @if ($noShowTicket)
                                Mark <strong>{{ $noShowTicket->user->name }}</strong>
                                ({{ $noShowTicket->queue_number }}) as a no-show.
                            @else
                                This ticket is no longer available.
                            @endif
                        </p>

                        <ul class="mt-3 space-y-1 rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-900">
                            <li>The place is released, so somebody else can take it.</li>
                            <li>
                                @if ($noShowTicket && $noShowTicket->status->isInLine())
                                    The client was checked in, so this is excused: it counts for
                                    nothing against them.
                                @else
                                    The client did not come, so this counts as an unexcused no-show.
                                    Three of them pause their new bookings for a fortnight.
                                @endif
                            </li>
                            <li>The client is told that their appointment was closed out.</li>
                        </ul>

                        <label for="no-show-note" class="mt-4 block text-sm font-medium text-slate-900">
                            Note (optional)
                        </label>
                        <input id="no-show-note" type="text" wire:model="noShowNote"
                               placeholder="e.g. Called twice, no answer"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500">
                        @error('noShowNote')
                            <p class="mt-1 text-xs font-medium text-rose-700">{{ $message }}</p>
                        @enderror

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" wire:click="confirmNoShow"
                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                    class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">
                                Yes, mark as a no-show
                            </button>
                            <button type="button" wire:click="cancelModal"
                                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Cancel
                            </button>
                        </div>
                    @endif
                @elseif ($this->modal === 'cancel_booking')
                    <h3 id="console-dialog-title" class="text-lg font-bold text-slate-900">
                        Cancel this booking
                    </h3>

                    @if (($outcomes['cancel_booking'] ?? null))
                        <div role="status" class="mt-4 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3">
                            <p class="text-sm font-semibold text-emerald-900">
                                &#10003; {{ $outcomes['cancel_booking']['text'] }}
                            </p>
                            <button type="button" wire:click="cancelModal"
                                    class="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Done
                            </button>
                        </div>
                    @else
                        @php $cancellingTicket = $this->cancellingBookingId ? \App\Models\Appointment::find($this->cancellingBookingId) : null; @endphp

                        <p class="mt-2 text-sm text-slate-700">
                            @if ($cancellingTicket)
                                Cancel the booking of <strong>{{ $cancellingTicket->user->name }}</strong>
                                ({{ $cancellingTicket->queue_number }}).
                            @else
                                This ticket is no longer available.
                            @endif
                        </p>

                        <ul class="mt-3 space-y-1 rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-900">
                            <li>The place is released, so somebody else can take it.</li>
                            <li>
                                This does not count against the client. It is the office's
                                decision, not their absence, so it is not a no-show and
                                it is not a strike.
                            </li>
                            <li>The client is told it was cancelled, and why, on their page and by message.</li>
                        </ul>

                        <label for="cancel-booking-reason" class="mt-4 block text-sm font-medium text-slate-900">
                            Why is this booking being closed? <span class="text-rose-600">*</span>
                        </label>
                        <textarea id="cancel-booking-reason" rows="3" wire:model="cancelBookingReason"
                                  placeholder="e.g. This service is not offered today. Please book again on another day."
                                  class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"></textarea>
                        @error('cancelBookingReason')
                            <p class="mt-1 text-xs font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500">
                            The client reads this on their appointment page. Say what they should
                            do next.
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" wire:click="confirmCancelBooking"
                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                    class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">
                                Yes, cancel the booking
                            </button>
                            <button type="button" wire:click="cancelModal"
                                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Cancel
                            </button>
                        </div>
                    @endif
                @elseif ($this->modal === 'not_complete')
                    <h3 id="console-dialog-title" class="text-lg font-bold text-slate-900">
                        Not completed
                    </h3>

                    @if (($outcomes['not_complete'] ?? null))
                        <div role="status" class="mt-4 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3">
                            <p class="text-sm font-semibold text-emerald-900">
                                &#10003; {{ $outcomes['not_complete']['text'] }}
                            </p>
                            <button type="button" wire:click="cancelModal"
                                    class="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Done
                            </button>
                        </div>
                    @else
                        @php $notCompletedTicket = \App\Models\Appointment::find($this->notCompletingId); @endphp

                        <p class="mt-2 text-sm text-slate-700">
                            @if ($notCompletedTicket)
                                Record that the service could not be completed for
                                <strong>{{ $notCompletedTicket->user->name }}</strong>
                                ({{ $notCompletedTicket->queue_number }}).
                            @else
                                This ticket is no longer available.
                            @endif
                        </p>

                        <ul class="mt-3 space-y-1 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-700">
                            <li>The client was at the counter, so this is not a no-show and counts for nothing against them.</li>
                            <li>No fee is recorded, because nothing was issued.</li>
                            <li>The client is told why, and can book again.</li>
                        </ul>

                        <label for="not-completed-reason" class="mt-4 block text-sm font-medium text-slate-900">
                            Why it could not be completed <span class="text-rose-600">*</span>
                        </label>
                        <textarea id="not-completed-reason" rows="3" wire:model="notCompletedReason"
                                  placeholder="e.g. Client did not bring the PWD ID required for this service."
                                  class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"></textarea>
                        @error('notCompletedReason')
                            <p class="mt-1 text-xs font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500">
                            The client sees this on their appointment page. It is what they do next.
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" wire:click="confirmNotComplete"
                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                    class="rounded-lg bg-purple-700 px-4 py-2 text-sm font-bold text-white hover:bg-purple-800">
                                Record as not completed
                            </button>
                            <button type="button" wire:click="cancelModal"
                                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Cancel
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
