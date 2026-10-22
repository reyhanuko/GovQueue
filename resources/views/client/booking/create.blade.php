@extends('layouts.app')

@section('title', 'Book '.$service->name)

@section('content')
    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('services.index') }}" class="text-blue-700 hover:underline">All services</a>
        <span class="mx-1 text-slate-400">/</span>
        <a href="{{ route('services.show', $service) }}" class="text-blue-700 hover:underline">{{ $service->name }}</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">Choose a slot</span>
    </nav>

    <div class="mt-4">
        <h1 class="text-2xl font-bold text-slate-900">Book {{ $service->name }}</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ $service->window->name }} &middot; about {{ $service->duration_minutes }} minutes
            @if ($service->fee > 0)
                &middot; &#8369;{{ number_format((float) $service->fee, 2) }} payable at the counter
            @endif
        </p>
    </div>

    @if ($hasActiveBooking)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-900">You already hold a booking for this service</h2>
            <p class="mt-1 text-sm text-amber-800">
                One live booking per service keeps the queue fair. Move your existing booking
                instead of creating a second one.
            </p>
            <ul class="mt-3 space-y-2">
                @foreach ($activeBookings as $existing)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white px-4 py-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">
                                {{ $existing->scheduledDateLabel() }} &middot; {{ $existing->scheduledTimeLabel() }}
                            </p>
                            <p class="font-mono text-xs text-slate-500">{{ $existing->reference_number }}</p>
                        </div>
                        <a href="{{ route('client.appointments.reschedule', $existing) }}"
                           class="rounded-lg border border-amber-500 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100">
                            Move this appointment
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($availability->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="text-sm font-medium text-slate-700">No open slots available for this service.</p>
            <p class="mt-1 text-sm text-slate-500">
                Every slot in the coming weeks is either full or the window is closed.
                Walk-ins are still accommodated through the office's existing process.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('client.booking.store', $service) }}" 
              class="mt-6 grid gap-6 lg:grid-cols-3"
              x-data="{ 
                  selectedSlot: null, 
                  selectedDate: null, 
                  selectedTime: null,
                  selectSlot(date, time, slotId) {
                      this.selectedDate = date;
                      this.selectedTime = time;
                      this.selectedSlot = slotId;
                  }
              }">
            @csrf

            {{-- min-w-0 is load-bearing, not decoration. A grid item defaults to
                 min-width:auto, and <fieldset> carries a user-agent default of
                 min-inline-size:min-content, so between them this column
                 refuses to shrink below its widest content. The day strip is
                 meant to scroll sideways inside itself; without these two the
                 strip instead stretched the whole page to fit all thirty days,
                 which is the same wall the strip was added to remove. --}}
            <div class="min-w-0 space-y-5 lg:col-span-2">
                {{-- Selection feedback.

                     This used to tell the reader to "scroll down to complete your
                     booking", which described the problem instead of solving it -
                     step 1 was a wall, so scrolling was the only way past it.
                     Step 1 is short now, and the chosen slot is repeated in the
                     confirm panel beside the button, which is where somebody
                     about to commit actually looks. --}}
                <div x-show="selectedSlot"
                     x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 transform -translate-y-2"
                     x-transition:enter-end="opacity-100 transform translate-y-0"
                     class="rounded-xl border border-green-200 bg-green-50 p-4 shadow-sm">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 flex-shrink-0 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-green-900">Time slot selected</p>
                            <p class="mt-1 text-sm text-green-800">
                                <span x-text="selectedDate"></span> at <span x-text="selectedTime"></span>
                            </p>
                            <p class="mt-1 text-xs text-green-700">
                                Check the panel on the right, then confirm.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- min-w-0 on the fieldset is load-bearing, not decoration. <fieldset>
                     carries a user-agent default of min-inline-size:min-content,
                     so it refuses to shrink below its own content no matter what
                     its parent permits. Measured at a 390px viewport: without it
                     this fieldset laid out at 1706px and dragged the document to
                     1722px, so the day strip stretched the page to fit all thirty
                     days instead of scrolling inside itself - the same wall the
                     strip was added to remove. The ul's overflow-x-auto cannot
                     break the cycle, because the width is being resolved on the
                     fieldset's min-width, one level above the scroller. --}}
                <fieldset class="min-w-0 rounded-xl border border-slate-200 bg-white p-6">
                    <legend class="px-1 text-sm font-bold text-slate-900">1. Choose a time slot</legend>

                    {{--
                        A day, then the times inside it.

                        This used to render every open slot for the whole
                        booking horizon in one column - around thirty days of
                        them - so steps 2 and 3 sat below a wall, and the
                        selection banner could only say "scroll down to
                        complete your booking", which is an accurate description
                        of the problem rather than a solution to it.

                        A strip of days at the top, then one day's times, turns
                        two hundred-odd buttons into about eight. Plain links
                        carrying ?date=, which the controller already honoured,
                        so the day can be chosen with no JavaScript at all and
                        still works on the slow connection this page is likely
                        read on.
                    --}}
                    <nav aria-label="Choose a day" class="mt-3 min-w-0">
                        <ul class="flex w-full gap-2 overflow-x-auto pb-2">
                            @foreach ($availability as $date => $slots)
                                @php
                                    $open = collect($slots)
                                        ->reject(fn ($s) => in_array($s->id, $bookedSlotIds[$date] ?? [], true))
                                        ->count();
                                    $isCurrent = $selectedDate === $date;
                                @endphp
                                <li class="shrink-0">
                                    <a href="{{ route('client.booking.create', [$service, 'date' => $date]) }}"
                                       @if ($isCurrent) aria-current="true" @endif
                                       class="block w-24 rounded-lg border px-3 py-2 text-center transition-colors
                                              {{ $isCurrent
                                                  ? 'border-blue-700 bg-blue-700 text-white'
                                                  : 'border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-slate-50' }}">
                                        <span class="block text-xs font-semibold uppercase tracking-wide {{ $isCurrent ? 'text-blue-100' : 'text-slate-500' }}">
                                            {{ \Illuminate\Support\Carbon::parse($date)->format('D') }}
                                        </span>
                                        <span class="mt-0.5 block text-lg font-bold leading-none">
                                            {{ \Illuminate\Support\Carbon::parse($date)->format('j') }}
                                        </span>
                                        <span class="mt-0.5 block text-xs {{ $isCurrent ? 'text-blue-100' : 'text-slate-500' }}">
                                            {{ \Illuminate\Support\Carbon::parse($date)->format('M') }}
                                            &middot;
                                            @if ($open > 0)
                                                {{ $open }} free
                                            @else
                                                full
                                            @endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>

                    @if ($selectedDate)
                        <p class="mt-3 text-sm text-slate-600">
                            Showing <strong>{{ $dayLabel($selectedDate) }}</strong>.
                            <a href="{{ route('client.booking.create', $service) }}"
                               class="font-medium text-blue-700 hover:underline">Show every day instead</a>
                        </p>
                    @endif

                    <div class="mt-3 space-y-5">
                        @foreach ($availability as $date => $slots)
                            {{-- With a day chosen, only that day is rendered. The others
                                 are one click away in the strip above. --}}
                            @if ($selectedDate !== null && $selectedDate !== $date)
                                @continue
                            @endif

                            <div id="day-{{ $date }}"
                                 class="scroll-mt-24 rounded-lg {{ $selectedDate === $date ? 'bg-blue-50/60 p-3 ring-1 ring-blue-200' : '' }}">
                                @if ($selectedDate === null)
                                    <h3 class="text-sm font-semibold text-slate-800">{{ $dayLabel($date) }}</h3>
                                @endif

                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($slots as $slot)
                                        @php $alreadyMine = in_array($slot->id, $bookedSlotIds[$date] ?? [], true); @endphp
                                        <li>
                                            <label class="block cursor-pointer">
                                                <input type="radio" name="time_slot_id" value="{{ $slot->id }}"
                                                       class="peer sr-only"
                                                       @if ($alreadyMine) disabled @endif
                                                       @click="selectSlot('{{ $dayLabel($date) }}', '{{ $slot->formatTime($slot->start_time) }}', {{ $slot->id }})">
                                                <span class="block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700
                                                             peer-checked:border-blue-700 peer-checked:bg-blue-50 peer-checked:ring-1 peer-checked:ring-blue-700
                                                             hover:border-blue-300 transition-colors
                                                             @if ($alreadyMine) opacity-50 @endif">
                                                    <span class="font-medium">{{ $slot->formatTime($slot->start_time) }}</span>
                                                    <span class="block text-xs text-slate-500">
                                                        @if ($alreadyMine)
                                                            Already booked
                                                        @else
                                                            {{ $slot->remainingCapacity() }}/{{ $slot->capacity }} left
                                                        @endif
                                                    </span>
                                                </span>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>

                    @error('time_slot_id')
                        <p class="mt-4 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </fieldset>

                <fieldset class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                    <legend class="px-1 text-sm font-bold text-amber-900">
                        2. Priority claim <span class="font-normal text-amber-700">(optional)</span>
                    </legend>

                    <p class="text-sm text-amber-900">
                        Claiming priority here does <strong>not</strong> move you up the queue yet.
                        Staff must verify your claim at the counter first.
                    </p>

                    <div class="mt-4 space-y-3">
                        @foreach ($priorities as $key => $meta)
                            <label class="flex items-start gap-3 text-sm text-slate-800
                                          {{ $meta['available'] ? '' : 'opacity-50' }}">
                                <input type="checkbox" name="claims_{{ $key }}" value="1"
                                       @disabled(! $meta['available'])
                                       @checked(old('claims_'.$key))
                                       class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                                <span>
                                    <span class="font-medium">{{ $meta['label'] }}</span>
                                    <span class="ml-1 text-xs text-slate-500">({{ $meta['basis'] }})</span>
                                    @if ($key === 'senior_citizen')
                                        <span class="block text-xs text-slate-600">
                                            @if ($meta['available'])
                                                Recognised from your date of birth.
                                            @else
                                                Your date of birth does not yet meet the RA 9994 threshold of
                                                {{ config('govqueue.senior_citizen_age') }}.
                                            @endif
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @endforeach

                        <div>
                            <label for="priority_reference" class="block text-sm font-medium text-slate-700">
                                Supporting reference
                                <span class="font-normal text-slate-500">(required for PWD and pregnant claims)</span>
                            </label>
                            <input id="priority_reference" name="priority_reference" type="text"
                                   placeholder="e.g. PWD-1234567 or clinic referral no."
                                   value="{{ old('priority_reference') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('priority_reference')
                                <p class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </fieldset>

                <fieldset class="min-w-0 rounded-xl border border-slate-200 bg-white p-6">
                    <legend class="px-1 text-sm font-bold text-slate-900">3. Anything staff should know?</legend>
                    <label for="client_note" class="sr-only">Note for staff</label>
                    <textarea id="client_note" name="client_note" rows="3"
                              placeholder="Optional. For example: I will bring my original birth certificate."
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('client_note') }}</textarea>
                    @error('client_note')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </fieldset>
            </div>

            <aside>
                <div class="sticky top-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="font-bold text-slate-900">Confirm your booking</h2>

                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Service</dt>
                            <dd class="text-right font-medium text-slate-800">{{ $service->name }}</dd>
                        </div>
                        {{-- The slot the reader chose, next to the button that commits
                             them to it. Previously the only confirmation of a
                             selection was a banner at the top of a long page. --}}
                        <div class="flex justify-between gap-2" x-show="selectedSlot" x-cloak>
                            <dt class="text-slate-500">Your slot</dt>
                            <dd class="text-right font-medium text-slate-800">
                                <span x-text="selectedDate"></span><br>
                                <span x-text="selectedTime"></span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2" x-show="! selectedSlot" x-cloak>
                            <dt class="text-slate-600">Your slot</dt>
                            <dd class="text-right text-slate-500">Not chosen yet</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Window</dt>
                            <dd class="text-right font-medium text-slate-800">{{ $service->window->name }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Fee</dt>
                            <dd class="text-right font-medium text-slate-800">
                                {{ $service->fee > 0 ? '₱'.number_format((float) $service->fee, 2) : 'Free' }}
                            </dd>
                        </div>
                    </dl>

                    {{-- Refuses to submit until a slot is chosen, rather than
                         accepting it and bouncing the reader off to a validation
                         error at the top of a page they have to scroll down
                         again to fix. A disabled button on its own says
                         nothing, so the reason is spelled out beneath it. --}}
                    <button type="submit" @disabled($hasActiveBooking)
                            x-bind:disabled="! selectedSlot"
                            class="mt-5 w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                        {{ $hasActiveBooking ? 'Booking already held' : 'Confirm appointment' }}
                    </button>

                    <p x-show="! selectedSlot && ! {{ $hasActiveBooking ? 'true' : 'false' }}" x-cloak
                       class="mt-2 text-xs text-slate-500">
                        Choose a time in step 1 first.
                    </p>

                    <p class="mt-3 text-xs leading-relaxed text-slate-500">
                        Your slot is only reserved once you see a queue number. You will receive a
                        confirmation, and a reminder before your appointment.
                    </p>
                </div>
            </aside>
        </form>
    @endif
@endsection