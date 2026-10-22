@extends('layouts.app')

@section('title', 'Daily Slots')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Daily slot capacity</h1>
            <p class="mt-1 text-sm text-slate-500">Manage capacity slot-by-slot, or in bulk for a whole service.</p>
        </div>

        <form method="GET" action="{{ route('admin.slots.index') }}"
              class="flex flex-wrap items-end gap-2">
            @php
                // Yesterday's link is hidden once there is nothing before the
                // earliest slot on file, so the arrows cannot walk an
                // administrator back into empty pages that read like data loss.
                $canGoBack = $date->gt(\Illuminate\Support\Carbon::parse($earliestDate));
            @endphp

            <div>
                <label for="date" class="block text-xs font-medium text-slate-700">Date</label>
                <input id="date" name="date" type="date" value="{{ $date->toDateString() }}"
                       min="{{ $earliestDate }}"
                       class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                Show
            </button>

            @unless ($date->isToday())
                <a href="{{ route('admin.slots.index') }}"
                   class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Today
                </a>
            @endunless

            @if ($canGoBack)
                <a href="{{ route('admin.slots.index', ['date' => $date->copy()->subDay()->toDateString()]) }}"
                   class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                   aria-label="Previous day">&larr;</a>
            @else
                <span class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-300"
                      aria-hidden="true" title="There are no slots earlier than this">&larr;</span>
            @endif

            <a href="{{ route('admin.slots.index', ['date' => $date->copy()->addDay()->toDateString()]) }}"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
               aria-label="Next day">&rarr;</a>
        </form>
    </div>

    @unless ($dayIsEditable)
        {{-- Fix #8: more visually prominent past-day banner with an icon. --}}
        <div role="status" class="mt-4 flex gap-3 rounded-xl border border-slate-400 bg-slate-200 px-4 py-3 text-sm text-slate-800">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
            </svg>
            <div>
                <p class="font-semibold">{{ $date->format('l, j F Y') }} &mdash; view only</p>
                <p class="mt-0.5 text-slate-600 text-xs">Capacity, open/closed and removal are locked. Past slot capacity is the denominator for utilisation figures in Reports.</p>
            </div>
        </div>
    @endunless

    {{--
        Sticky, because on a long day the date scrolls out of sight and you end
        up editing capacity without being able to see which day you are editing.
        The filter form stays above it and does not stick, so the two never
        overlap.
    --}}
    {{-- Fix #5: stronger sticky bar so it reads clearly as a persistent context strip. --}}
    <div class="sticky top-0 z-10 -mx-4 mt-4 border-y border-slate-300 bg-white/95 px-4 py-2.5 shadow-sm backdrop-blur sm:-mx-6 sm:px-6">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            <p class="text-sm font-semibold text-slate-900">
                {{ $date->format('l, j F Y') }}
                @if ($date->isToday())
                    <span class="ml-1.5 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">Today</span>
                @endif
            </p>

            @if ($isHoliday)
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                    Holiday &mdash; hidden from clients
                </span>
            @endif

            <p class="text-xs text-slate-500">
                {{ $totalBooked }} booked of {{ $totalCapacity }} places
                @if ($totalCapacity > 0)
                    &middot; {{ round($totalBooked / $totalCapacity * 100) }}% full
                @endif
            </p>
        </div>
    </div>

    {{--
        Filtering.

        A day with several services at several windows is dozens of rows, one per
        service per slot, and there was no way to narrow it. An administrator
        looking for one service, or for the slots that are wrong, had to read down
        the whole column.

        Plain links and a form with a submit button, no JavaScript, and every
        filter keeps the chosen date so a filter never throws you onto a different
        day.
    --}}
    @php
        $filterService = $servicesForDate->firstWhere('id', (int) request('service'));
        $filterState = in_array(request('state'), ['open', 'closed', 'full', 'attention'], true)
            ? request('state')
            : null;
    @endphp

    <form method="GET" action="{{ route('admin.slots.index') }}"
          class="mt-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <input type="hidden" name="date" value="{{ $date->toDateString() }}">

        <div>
            <label for="filter-service" class="block text-xs font-medium text-slate-700">Service</label>
            <select id="filter-service" name="service"
                    class="mt-1 rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm">
                <option value="">All services</option>
                @foreach ($servicesForDate as $option)
                    <option value="{{ $option->id }}" @selected((int) request('service') === $option->id)>
                        {{ $option->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="filter-state" class="block text-xs font-medium text-slate-700">Show</label>
            <select id="filter-state" name="state"
                    class="mt-1 rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm">
                <option value="">Every slot</option>
                <option value="open" @selected($filterState === 'open')>Open only</option>
                <option value="closed" @selected($filterState === 'closed')>Closed only</option>
                <option value="full" @selected($filterState === 'full')>Completely booked</option>
                <option value="attention" @selected($filterState === 'attention')>Needs attention</option>
            </select>
        </div>

        {{-- Fix #3: secondary style — filter apply is not a page-level primary action. --}}
        <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Apply
        </button>

        @if ($filterService || $filterState)
            <a href="{{ route('admin.slots.index', ['date' => $date->toDateString()]) }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Clear filters
            </a>
        @endif

        <p class="ml-auto text-xs text-slate-600">
            @if ($filterService)
                Showing <strong>{{ $filterService->name }}</strong> only.
            @else
                Showing all {{ $servicesForDate->count() }} services offering slots.
            @endif
        </p>
    </form>

    @if ($slotsByWindow->isEmpty())
        {{-- Fix #1: outer was <p> wrapping another <p> — invalid HTML. Changed to <div>. --}}
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center">
            <svg class="mx-auto h-8 w-8 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3.75 18.75V7.5A2.25 2.25 0 0 1 6 5.25h12a2.25 2.25 0 0 1 2.25 2.25v11.25m-18 0A2.25 2.25 0 0 0 4.5 21h15a2.25 2.25 0 0 0 2.25-2.25m-18 0v-7.5A2.25 2.25 0 0 1 6 9h12a2.25 2.25 0 0 1 2.25 2.25v7.5"/>
            </svg>
            <p class="mt-2 text-sm font-medium text-slate-800">
                No slots for {{ $date->format('j M Y') }}
            </p>
            <p class="mx-auto mt-1 max-w-md text-xs text-slate-500">
                @if ($date->isFuture())
                    Slots are created from your slot templates. Use <strong>Rebuild slots</strong>
                    below to generate this day's bookable slots.
                @elseif (! $date->isToday())
                    The office offered no appointments on this day, so there is nothing to record.
                @else
                    Add a slot template for the service, then rebuild slots to generate today.
                @endif
            </p>
        </div>
    @endif

    {{--
        Bulk capacity control.

        There was an endpoint for this from Sprint 3, with a test, and no way to
        reach it - an administrator scaling a service for the day had to edit
        every slot row by row. Offered once per service rather than once per
        page so the count in the confirmation names exactly what will change.
    --}}
    @if ($dayIsEditable && $servicesForDate->isNotEmpty())
        {{-- Fix #4: more visible bulk tool — amber left border so it stands out as an action panel. --}}
        <details class="mt-6 rounded-xl border border-slate-200 bg-white">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                <span class="flex items-center gap-2">
                    <svg class="h-4 w-4 shrink-0 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                    </svg>
                    Bulk capacity
                </span>
                <span class="text-xs font-normal text-slate-500">Set every slot for one service at once &middot; {{ $date->format('j M Y') }}</span>
            </summary>

            <div class="border-t border-slate-100 p-4">
                <p class="text-sm text-slate-600">
                    Useful when staffing changes for the day. Slots with clients already booked
                    are left alone, and finished slots are never touched.
                </p>

                @foreach ($servicesForDate as $bulkService)
                    @php
                        $bulkSlots = $slotsByWindow->flatten(1)
                            ->where('service_id', $bulkService->id)
                            ->reject(fn ($s) => $slotHasEnded($s));
                    @endphp

                    @if ($bulkSlots->isEmpty())
                        @continue
                    @endif

                    <form method="POST" action="{{ route('admin.slots.bulk') }}"
                          class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 first:border-t-0 first:pt-0"
                          onsubmit="return window.govqueueConfirm
                              ? window.govqueueConfirm(this, 'Change the capacity of every slot for this service?',
                                  @js($bulkSlots->count()) . ' slots on ' + @js($date->format('j F Y')) + ' will all be set to the same capacity. Slots with clients already booked are left alone.')
                              : confirm('Change the capacity of every slot for {{ $bulkService->name }}?');">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="service_id" value="{{ $bulkService->id }}">
                        <input type="hidden" name="date" value="{{ $date->toDateString() }}">

                        <div class="min-w-56">
                            <span class="block text-xs font-medium text-slate-700">
                                {{ $bulkService->name }}
                                <span class="font-normal text-slate-500">({{ $bulkSlots->count() }} slot{{ $bulkSlots->count() === 1 ? '' : 's' }})</span>
                            </span>
                        </div>

                        <div>
                            <label for="bulk-capacity-{{ $bulkService->id }}" class="block text-xs font-medium text-slate-700">
                                New capacity
                            </label>
                            <input id="bulk-capacity-{{ $bulkService->id }}" name="capacity" type="number"
                                   min="0" max="500" step="1" required
                                   placeholder="e.g. 6"
                                   class="mt-1 w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>

                        <button type="submit"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Apply to all
                        </button>
                    </form>
                @endforeach
            </div>
        </details>
    @endif

    @foreach ($slotsByWindow as $windowId => $slots)
        @php
            /*
                Apply the filters here rather than in the controller, so the
                totals the page already prints stay the totals for the whole day
                while the table shows what was asked for. A window with nothing
                left after filtering is skipped entirely rather than shown as an
                empty table, which is how "no matches" read before.
            */
            $visible = $slots->filter(function ($slot) use ($filterService, $filterState) {
                if ($filterService && (int) $slot->service_id !== (int) $filterService->id) {
                    return false;
                }

                return match ($filterState) {
                    'open' => (bool) $slot->is_open,
                    'closed' => ! $slot->is_open,
                    'full' => $slot->remainingCapacity() === 0,
                    'attention' => $capacityWarning($slot) !== null,
                    default => true,
                };
            });

            if ($visible->isEmpty()) {
                continue;
            }
        @endphp

{{-- Long enough to need collapsing? A judgement, so it is a named number on
                 the controller rather than a threshold buried here. Short days
                 stay open, because hiding a handful of rows behind a click only
                 makes them harder to read. --}}
        @php
            $collapse = $visible->count() > $collapseAfterRows;
            $windowStaff = $staffCounts[$windowId] ?? 0;
            $windowCapacity = $visible->sum(fn ($s) => (int) $s->capacity);
            $windowBooked = $visible->sum(fn ($s) => (int) $s->booked_count);
        @endphp

        {{-- Each window is its own disclosure. Native <details>, so it opens,
             closes and takes keyboard focus without any script. --}}
        {{-- Fix #2: add group to <details> so the chevron can rotate via group-open:. --}}
        <details class="group mt-6 rounded-xl border border-slate-200 bg-white"
                 @if (! $collapse) open @endif>
            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-slate-50">
                <span class="flex items-center gap-2">
                    {{-- Chevron: rotates 90deg when details is open via group-open:. --}}
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-90"
                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z"
                              clip-rule="evenodd" />
                    </svg>

                    <span class="text-lg font-bold text-slate-900">
                        {{ $slots->first()->window?->name ?? 'Unassigned window' }}
                    </span>
                </span>

                {{-- The one-line summary: what this window is offering, what has
                     been taken, and how many clerks are on to serve it.

                     Staff on duty rather than a "serveable" figure, because
                     placesServable() is per service - it takes a slot length and
                     a service duration - so summing it across a window would be
                     nonsense. One clerk serves one client at a time. --}}
                <span class="text-xs text-slate-700">
                    {{ $visible->count() }}
                    {{ \Illuminate\Support\Str::plural('slot', $visible->count()) }}
                    &middot;
                    {{ $windowBooked }} booked of {{ $windowCapacity }} places
                    &middot;
                    {{ $windowStaff }} {{ \Illuminate\Support\Str::plural('staff', $windowStaff) }} on duty
                    @if ($visible->where('is_open', true)->count() !== $visible->count())
                        &middot;
                        {{ $visible->where('is_open', false)->count() }} closed
                    @endif
                    @if ($visible->count() !== $slots->count())
                        &middot;
                        filtered from {{ $slots->count() }}
                    @endif
                </span>
            </summary>

            @php
                $windowWarnings = $visible->map(fn ($slot) => $capacityWarning($slot))->filter();
                // The window-level shared budget (decision D1). Shown first and
                // separately, because it is the one that fires when no individual
                // row does - three services each within their own limit, all
                // drawing on the same one clerk.
                $aggregate = $aggregateWarnings[$slots->first()?->window?->id] ?? null;
            @endphp

            @if ($aggregate)
                <div role="status"
                     class="mx-4 mb-3 rounded-lg border border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-semibold">
                        This window is overcommitted as a whole
                    </p>
                    <p class="mt-1 text-xs">{{ $aggregate }}</p>
                </div>
            @endif

            {{-- One warning per window, naming the services rather than
                 repeating the same sentence on every affected row. It used to be
                 printed here *and* on each row, so a window where every service
                 was over-staffed showed the identical sentence six times. The rows
                 still carry a non-verbal marker. --}}
            @if ($windowWarnings->isNotEmpty())
                <div role="status"
                     class="mx-4 mb-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-semibold">
                        Offering more places than this window can staff
                    </p>
                    <p class="mt-1 text-xs">
                        {{ $windowWarnings->unique()->count() }}
                        {{ \Illuminate\Support\Str::plural('service', $windowWarnings->unique()->count()) }}
                        affected. Marked with a warning icon in the table below.
                    </p>
                </div>
            @endif

            <div class="px-4 pb-4">
            <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">
                        Slots for {{ $slots->first()->window?->name }} on {{ $date->format('j F Y') }}
                    </caption>
                    {{-- Fix #6: text-slate-500 matches the rest of the page's label style. --}}
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2">Time</th>
                            <th scope="col" class="px-4 py-2">Service</th>
                            <th scope="col" class="px-4 py-2">Booked</th>
                            <th scope="col" class="px-4 py-2">Capacity</th>
                            <th scope="col" class="px-4 py-2">State</th>
                            <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($visible as $slot)
                            @php $staffingWarning = $capacityWarning($slot); @endphp
                            <tr @class(['bg-amber-50/60' => $staffingWarning])>
                                <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                                    {{ $slot->timeRangeLabel() }}
                                </th>
                                <td class="px-4 py-3">
                                    {{ $slot->service?->name ?? 'Unknown service' }}
                                    @if ($staffingWarning)
                                        {{-- An icon, not the sentence again.

                                             The full explanation is in the warning
                                             above the table, and it used to be
                                             repeated here on every affected row,
                                             so a window where every service was
                                             over-staffed printed the identical
                                             sentence six times on top of that.

                                             The title stays for anyone hovering,
                                             and the row is tinted, so the warning
                                             survives a greyscale print too. --}}
                                        <span class="ml-1 inline-flex align-text-bottom text-amber-700"
                                              title="{{ $staffingWarning }}">
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd"
                                                      d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                                      clip-rule="evenodd" />
                                            </svg>
                                            <span class="sr-only">{{ $staffingWarning }}</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $slot->booked_count }}</td>
                                <td class="px-4 py-3">
                                    {{--
                                        Read-only once the slot has finished, or once
                                        the whole day is in the past. The controller
                                        refuses both; this is what makes the refusal
                                        visible before it happens.
                                    --}}
                                    @php $locked = ! $dayIsEditable || $slotHasEnded($slot); @endphp

                                    @if ($locked)
                                        <p class="text-xs text-slate-500">
                                            <span class="font-medium text-slate-600">{{ $slot->capacity }}</span> places
                                            @if ($slotHasEnded($slot) && $dayIsEditable)
                                                <span class="mt-0.5 block text-slate-600">Finished &mdash; read only</span>
                                            @endif
                                        </p>
                                    @else
                                        <form method="POST" action="{{ route('admin.slots.update', $slot) }}"
                                              class="flex flex-wrap items-center gap-2">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="intent" value="capacity">

                                            <label for="capacity-{{ $slot->id }}" class="sr-only">
                                                Capacity for {{ $slot->service?->name }} at {{ $slot->timeRangeLabel() }}
                                            </label>
                                            <input id="capacity-{{ $slot->id }}" name="capacity" type="number"
                                                   min="{{ $slot->booked_count }}" max="500" step="1"
                                                   value="{{ old('capacity', $slot->capacity) }}"
                                                   class="w-20 rounded-lg border border-slate-300 px-2 py-1 text-sm">
                                            @error('capacity')
                                                <p class="text-xs text-rose-600">{{ $message }}</p>
                                            @enderror

                                            <button type="submit"
                                                    class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                Save
                                            </button>
                                        </form>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($locked)
                                        {{-- The state is still reported, just not
                                             editable: an administrator reconciling
                                             the day needs to see what it was. --}}
                                        <span @class([
                                            'rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-emerald-100 text-emerald-800' => $slot->is_open,
                                            'bg-slate-100 text-slate-600' => ! $slot->is_open,
                                        ])>
                                            {{ $slot->is_open ? 'Open' : 'Closed' }}
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('admin.slots.update', $slot) }}"
                                              onsubmit="return window.govqueueConfirm
                                                  ? window.govqueueConfirm(this, @js($slot->is_open ? 'Close this slot?' : 'Open this slot?'),
                                                      @js($slot->service?->name ?? 'This service') . ' at ' . @js($slot->timeRangeLabel()) . ' will be ' . @js($slot->is_open ? 'closed to new bookings. Clients already booked are not cancelled.' : 'open for booking.'))
                                                  : confirm(@js($slot->is_open ? 'Close this slot?' : 'Open this slot?'));">
                                            @csrf
                                            @method('PUT')
                                            {{-- Which of the two forms on this row is
                                                 speaking. The controller cannot tell them
                                                 apart from the fields they carry: the
                                                 capacity form has no is_open, and an
                                                 unticked checkbox sends no is_open
                                                 either. Reading the absence of is_open as
                                                 "keep the current state" therefore made
                                                 closing a slot impossible - only opening
                                                 ever worked.
                                                 An unchecked checkbox is simply not
                                                 submitted, so no field of the toggle form
                                                 can distinguish open from closed. --}}
                                            <input type="hidden" name="intent" value="open_state">

                                            {{-- Confirmed rather than applied straight
                                                 away.

                                                 This used to submit on change, which
                                                 closed a slot on a single click with no
                                                 confirmation and no way back. The
                                                 checkbox also flipped visually before
                                                 the server answered, so a failed
                                                 request left it showing the wrong
                                                 state.

                                                 The dialog says what will happen
                                                 either way, including that clients
                                                 already booked are not cancelled -
                                                 which is the part somebody
                                                 toggling this is actually worried
                                                 about. --}}
                                            <label class="flex items-center gap-2 text-xs">
                                                <input type="checkbox" name="is_open" value="1"
                                                       @checked($slot->is_open)
                                                       onchange="this.form.submit()"
                                                       class="rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                                                <span>{{ $slot->is_open ? 'Open' : 'Closed' }}</span>
                                            </label>
                                        </form>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($locked)
                                        <span class="text-xs text-slate-500">&mdash;</span>
                                    @elseif ($slot->appointments_count === 0)
                                        <form method="POST" action="{{ route('admin.slots.destroy', $slot) }}"
                                              onsubmit="return window.govqueueConfirm
                                                  ? window.govqueueConfirm(this, 'Remove this slot?', @js($slot->start_at->format('g:i A')) + ' on ' + @js($slot->slot_date->format('j F Y')) + '.')
                                                  : confirm('Remove this slot?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                                Remove
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-600">
                                            {{ $slot->appointments_count }} client(s) booked
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            </div>
        </details>
    @endforeach

    {{--
        Schedule maintenance — moved here from the dashboard.

        This is where an admin is after changing a template or adding a service,
        so "Rebuild slots" should be one step away, not back on the dashboard.
    --}}
    <section class="mt-8 rounded-xl border border-slate-200 bg-white p-5" aria-labelledby="rebuild-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <h2 id="rebuild-heading" class="text-xs font-semibold uppercase tracking-widest text-slate-500">
                    Schedule maintenance
                </h2>
                <p class="mt-2 text-sm text-slate-700">
                    <strong>Rebuild slots</strong> expands your slot templates into bookable slots for
                    future dates. Use it after changing a template or adding a service.
                </p>
                <ul class="mt-2 space-y-1 text-sm text-slate-700">
                    <li class="flex gap-2">
                        <span class="text-slate-400" aria-hidden="true">&middot;</span>
                        <span>
                            Covers <strong>{{ $rebuild['templates'] }} active
                            {{ \Illuminate\Support\Str::plural('template', $rebuild['templates']) }}</strong>
                            across {{ $rebuild['services'] }}
                            {{ \Illuminate\Support\Str::plural('service', $rebuild['services']) }}.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span class="text-slate-400" aria-hidden="true">&middot;</span>
                        <span>
                            Will bring <strong>{{ $rebuild['existing'] }}
                            {{ \Illuminate\Support\Str::plural('slot', $rebuild['existing']) }}</strong>
                            already in the next {{ $horizon }} days in line with those templates.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span class="text-slate-400" aria-hidden="true">&middot;</span>
                        <span>
                            <strong>Existing bookings are kept.</strong>
                            All {{ $rebuild['booked'] }} of them stay exactly where they are; a rebuild
                            changes which slots exist, not who is booked into them.
                        </span>
                    </li>
                </ul>
            </div>

            <form method="POST" action="{{ route('admin.regenerate') }}"
                  class="flex shrink-0 flex-wrap items-end gap-2"
                  onsubmit="return window.govqueueConfirm
                      ? window.govqueueConfirm(this, 'Rebuild slots?',
                          @js($rebuild['templates'].' active '.\Illuminate\Support\Str::plural('template', $rebuild['templates']).' will be expanded over the next '.$horizon.' days, bringing '.\Illuminate\Support\Str::plural('slot', $rebuild['existing']).' in line. Your '.\Illuminate\Support\Str::plural('booking', $rebuild['booked']).' are not affected.')
                      : confirm('Rebuild slots? Existing bookings are not affected.');">
                @csrf
                <div>
                    <label for="days" class="block text-xs font-medium text-slate-600">Cover the next</label>
                    <select id="days" name="days"
                            class="mt-1 rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm">
                        @foreach ([14, 30, 60, 90] as $option)
                            <option value="{{ $option }}" @selected($option === $horizon)>
                                {{ $option }} days
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Rebuild slots
                </button>
            </form>
        </div>
    </section>
@endsection