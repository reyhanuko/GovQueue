@extends('layouts.app')

@section('title', 'Daily Slots')

@section('content')
    @include('admin.partials.nav')

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
            <div>
                <label for="date" class="block text-xs font-medium text-slate-700">Date</label>
                <input id="date" name="date" type="date" value="{{ $date->toDateString() }}"
                       class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">
                Show
            </button>
            <a href="{{ route('admin.slots.index', ['date' => $date->copy()->subDay()->toDateString()]) }}"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
               aria-label="Previous day">&larr;</a>
            <a href="{{ route('admin.slots.index', ['date' => $date->copy()->addDay()->toDateString()]) }}"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
               aria-label="Next day">&rarr;</a>
        </form>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <p class="text-sm font-medium text-slate-700">
            {{ $date->format('l, j F Y') }}
            @if ($date->isToday())
                <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">Today</span>
            @endif
        </p>

        @if ($isHoliday)
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                Declared a holiday &mdash; these slots are hidden from clients
            </span>
        @endif

        <p class="text-sm text-slate-600">
            {{ $totalBooked }} booked of {{ $totalCapacity }} places
            @if ($totalCapacity > 0)
                &middot; {{ round($totalBooked / $totalCapacity * 100) }}% full
            @endif
        </p>
    </div>

    @if ($slotsByWindow->isEmpty())
        <p class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">
            No slots have been generated for {{ $date->format('j M Y') }}.
            @if ($date->isFuture())
                Use <strong>Rebuild slots</strong> on the dashboard to generate them.
            @endif
        </p>
    @endif

    @foreach ($slotsByWindow as $windowId => $slots)
        <section class="mt-6" aria-labelledby="window-{{ $windowId }}">
            <h2 id="window-{{ $windowId }}" class="text-lg font-bold text-slate-900">
                {{ $slots->first()->window?->name ?? 'Unassigned window' }}
            </h2>

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
                        @foreach ($slots as $slot)
                            <tr>
                                <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                                    {{ $slot->timeRangeLabel() }}
                                </th>
                                <td class="px-4 py-3">{{ $slot->service?->name ?? 'Unknown service' }}</td>
                                <td class="px-4 py-3">{{ $slot->booked_count }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('admin.slots.update', $slot) }}"
                                          class="flex items-center gap-2">
                                        @csrf
                                        @method('PUT')

                                        <label for="capacity-{{ $slot->id }}" class="sr-only">
                                            Capacity for {{ $slot->service?->name }} at {{ $slot->timeRangeLabel() }}
                                        </label>
                                        <input id="capacity-{{ $slot->id }}" name="capacity" type="number"
                                               min="{{ $slot->booked_count }}" max="500" step="1"
                                               value="{{ $slot->capacity }}"
                                               class="w-20 rounded-lg border border-slate-300 px-2 py-1 text-sm">

                                        <button type="submit"
                                                class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                            Save
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('admin.slots.update', $slot) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="capacity" value="{{ $slot->capacity }}">

                                        <label class="flex items-center gap-2 text-xs">
                                            <input type="checkbox" name="is_open" value="1"
                                                   @checked($slot->is_open)
                                                   onchange="this.form.submit()"
                                                   class="rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                                            <span>{{ $slot->is_open ? 'Open' : 'Closed' }}</span>
                                        </label>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($slot->appointments_count === 0)
                                        <form method="POST" action="{{ route('admin.slots.destroy', $slot) }}"
                                              onsubmit="return confirm('Remove this slot?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                                Remove
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">
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
