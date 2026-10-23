@extends('layouts.app')

@section('title', 'Daily Slots')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Daily slot capacity</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Adjust one day's capacity as the day unfolds &mdash; add a place because a second clerk
                opened, or close a slot that will not be served. Capacity can never drop below the number
                of clients already booked.
            </p>
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
            <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">
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
        {{-- Read-only banner. The rows below are individually disabled too, but a
             banner saying why is the difference between "this page is broken" and
             "this day is closed". --}}
        <div role="status" class="mt-4 rounded-xl border border-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-700">
            <p class="font-semibold">{{ $date->format('l, j F Y') }} is in the past &mdash; view only</p>
            <p class="mt-1 text-slate-600">
                These slots are the record of what was served that day. Capacity, open/closed and
                removal are all disabled, because the capacity of a finished slot is what the
                utilisation figures in Reports are calculated from.
            </p>
        </div>
    @endunless

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <p class="text-sm font-medium text-slate-800">
            {{ $date->format('l, j F Y') }}
            @if ($date->isToday())
                <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">Today</span>
            @endif
        </p>

        @if ($isHoliday)
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                Declared a holiday &mdash; these slots are hidden from clients
            </span>
        @endif

        <p class="text-sm text-slate-700">
            {{ $totalBooked }} booked of {{ $totalCapacity }} places
            @if ($totalCapacity > 0)
                &middot; {{ round($totalBooked / $totalCapacity * 100) }}% full
            @endif
        </p>
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

        <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">
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
        <p class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center">
            <p class="text-sm font-medium text-slate-800">
                No slots have been generated for {{ $date->format('j M Y') }}.
            </p>
            <p class="mx-auto mt-1 max-w-md text-xs text-slate-600">
                @if ($date->isFuture())
                    Slots are created from your slot templates. Rebuild them on the dashboard to
                    generate this day's bookable slots.
                @elseif (! $date->isToday())
                    {{-- A past day with no slots is a real answer - the office did
                         not offer any - and saying only "no slots" invites an
                         administrator to go looking for a generation step that
                         was never needed. --}}
                    The office offered no appointments on this day, so there is nothing to record.
                @else
                    Add a slot template for the service, then rebuild slots to generate today.
                @endif
            </p>
        </p>
    @endif

    {{--
        Bulk capacity control.

        There was an endpoint for this from Sprint 3, with a test, and no way to
        reach it - an administrator scaling a service for the day had to edit
        every slot row by row. Offered once per service rather than once per
        page so the count in the confirmation names exactly what will change.
    --}}
    @if ($dayIsEditable && $servicesForDate->isNotEmpty())
        <details class="mt-6 rounded-xl border border-slate-200 bg-white">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-sm font-semibold text-slate-800">
                <span>Set every slot for one service at once</span>
                <span class="text-xs font-normal text-slate-500">For {{ $date->format('j M Y') }}</span>
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

        <section class="mt-6" aria-labelledby="window-{{ $windowId }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="window-{{ $windowId }}" class="text-lg font-bold text-slate-900">
                    {{ $slots->first()->window?->name ?? 'Unassigned window' }}
                </h2>

                {{-- Per-window counts, so a long page can be taken in at a glance
                     instead of by scrolling. --}}
                <p class="text-xs text-slate-700">
                    {{ $visible->count() }}
                    {{ \Illuminate\Support\Str::plural('slot', $visible->count()) }}
                    &middot;
                    {{ $visible->sum(fn ($s) => $s->booked_count) }} booked
                    &middot;
                    {{ $visible->where('is_open', true)->count() }} open
                    @if ($visible->count() !== $slots->count())
                        <span class="text-slate-600">
                            (of {{ $slots->count() }} on this day)
                        </span>
                    @endif
                </p>
            </div>

            @php
                $windowWarnings = $visible->map(fn ($slot) => $capacityWarning($slot))->filter();
            @endphp

            @if ($windowWarnings->isNotEmpty())
                <div role="status" class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-semibold">This window is offering more places than it can serve</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
                        @foreach ($windowWarnings->unique() as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">
                        Slots for {{ $slots->first()->window?->name }} on {{ $date->format('j F Y') }}
                    </caption>
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
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
                                        {{-- Short form on the row; the full sentence
                                             is on the template form. --}}
                                        <span class="mt-1 block text-xs font-medium text-amber-800"
                                              title="{{ $staffingWarning }}">
                                            More places than this window can staff
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
                                        <form method="POST" action="{{ route('admin.slots.update', $slot) }}">
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
        </section>
    @endforeach
@endsection
