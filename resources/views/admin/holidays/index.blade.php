@extends('layouts.app')

@section('title', 'Holidays')

@section('content')
    <h1 class="text-2xl font-bold text-slate-900">Holidays and closures</h1>
    <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Declaring a holiday hides that date's slots from every booking screen. It does
        <strong>not</strong> cancel appointments already booked, so any affected clients are listed
        against their closure for someone to contact or rebook.
    </p>

    {{--
        Order matters here, and it is a compromise between two things that pull
        in opposite directions.

        "Declare a closure" is this screen's primary verb, so it stays at the top
        where the action is discoverable. Burying it under the list - which an
        earlier revision of this page did - only moved the scrolling: the person
        who came to add tomorrow's closure then had to scroll past every existing
        row to find the button.

        What actually costs vertical space is the second form. Seeding a year is
        a bulk import somebody runs once, not a daily task, so it collapses to a
        single summary line. What is left above the list is one compact grid row
        plus one line, which keeps the closures close to the top without hiding the
        action behind a click.

        Native <details> rather than a JS toggle: it works without script, is
        keyboard operable for free, and matches the disclosure the navbar already
        uses. It force-opens when its own form is showing a validation error, or a
        rejected submit would leave the offending field hidden in a closed box and
        the message would look as though it had vanished.
    --}}
    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6" aria-labelledby="add-holiday">
        <h2 id="add-holiday" class="text-lg font-bold text-slate-900">Declare a closure</h2>

        <form method="POST" action="{{ route('admin.holidays.store') }}"
              class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @csrf

            <x-admin.field name="date" type="date" label="Date" required
                          :value="old('date', today()->toDateString())"
                          :min="today()->toDateString()"
                          help="Today or later. A date that has already passed cannot be booked." />

            <x-admin.field name="name" label="Name" required
                          :value="old('name')" placeholder="Foundational Day" />

            <div class="sm:col-span-2">
                <x-admin.field name="notes" label="Notes" :value="old('notes')"
                              placeholder="Optional. For example, a separate non-working day." />
            </div>

            <div class="flex items-end">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                    Save closure
                </button>
            </div>
        </form>
    </section>

    <details class="mt-4 rounded-xl border border-slate-200 bg-white open:shadow-sm"
             @if ($errors->has('year')) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-6 py-4 [&::-webkit-details-marker]:hidden">
            <span class="text-lg font-bold text-slate-900">Add the national holidays</span>
            <span class="text-sm font-normal text-slate-500">Seed a whole year in one go</span>
        </summary>

        <div class="border-t border-slate-200 px-6 pb-6">
            <p class="mt-4 text-sm text-slate-600">
                Declares the regular national holidays for a year in one go, instead of typing
                each date. Dates you have already declared are left exactly as they are, so this is
                safe to run more than once and safe to run after hand-adding a local closure.
            </p>

            <form method="POST" action="{{ route('admin.holidays.seed') }}"
                  class="mt-4 flex flex-wrap items-end gap-3">
                @csrf

                <div class="min-w-[10rem]">
                    <label for="seed-year" class="block text-xs font-medium text-slate-700">Year</label>
                    <input id="seed-year" name="year" type="number" required
                           value="{{ old('year', $seedYear) }}"
                           min="{{ now()->year }}" max="2100"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>

                <button type="submit"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Add national holidays
                </button>
            </form>

            <p class="mt-3 text-xs text-slate-500">
                Covers the fixed-date regular holidays plus Maundy Thursday and Good Friday, which
                follow Easter. Eid&rsquo;l Fitr and Eid&rsquo;l Adha are lunar and are not included, because a
                guessed date would close the office on a day it is open &mdash; add those by hand from the
                proclamation. A year that has already passed cannot be seeded; earlier dates inside
                the current year are still recorded, and simply appear under past closures.
            </p>
        </div>
    </details>

    <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-bold text-slate-900">
            Upcoming closures
            <span class="ml-1 text-sm font-normal text-slate-500">({{ $upcoming->count() }})</span>
        </h2>

        {{-- Past closures are off by default: they cannot affect a booking, so
             they are noise above the rows that need a decision. Reachable on
             demand, and they stay in the database either way. --}}
        @if ($past->isNotEmpty())
            <a href="{{ route('admin.holidays.index', array_filter(['show_past' => 1, 'seed_year' => $seedYear])) }}"
               class="text-sm font-medium text-blue-700 hover:text-blue-900 hover:underline">
                Show past closures ({{ $past->count() }})
            </a>
        @endif
    </div>

    @if ($upcoming->isEmpty())
        <p class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500">
            No upcoming closures. Clients can book every day the slot templates cover.
        </p>
    @else
        {{-- Grouped by year so a seeded calendar reads as a handful of headings
             rather than a wall of individual rows. --}}
        @foreach ($upcoming->groupBy(fn ($holiday) => $holiday->date->year) as $year => $rows)
            <h3 class="mt-5 text-sm font-semibold uppercase tracking-wide text-slate-500">
                {{ $year }}
                <span class="font-normal normal-case">({{ $rows->count() }})</span>
            </h3>

            <ul class="mt-2 space-y-2">
                @foreach ($rows as $holiday)
                    @php
                        $booked = $affected[$holiday->id] ?? collect();
                        // isToday() and not isPast(): `date` casts to midnight, so
                        // isPast() is already true from 00:00 and would badge a
                        // closure on today as history.
                        $isToday = $holiday->date->isToday();
                    @endphp

                    <li class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-900">
                                    {{ $holiday->name }}
                                    <span class="ml-1 font-normal text-slate-500">
                                        {{ $isToday ? 'Today' : $holiday->date->format('l, j F Y') }}
                                    </span>
                                    @if ($isToday)
                                        <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">Today</span>
                                    @endif
                                </p>

                                @if ($holiday->notes)
                                    <p class="mt-1 text-sm text-slate-600">{{ $holiday->notes }}</p>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('admin.holidays.destroy', $holiday) }}"
                                  onsubmit="return window.govqueueConfirm
                                      ? window.govqueueConfirm(this, 'Remove this closure?', 'Clients will be able to book ' + @js($holiday->date->format('j F Y')) + ' again.')
                                      : confirm('Remove this closure? Clients will be able to book that date again.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                    Remove
                                </button>
                            </form>
                        </div>

                        @if ($booked->isNotEmpty())
                            <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3">
                                <p class="text-sm font-semibold text-amber-900">
                                    {{ $booked->count() }} client(s) still hold a booking on this date
                                </p>
                                <ul class="mt-2 space-y-1">
                                    @foreach ($booked as $appointment)
                                        <li class="text-sm text-amber-900">
                                            {{ $appointment->scheduledTimeLabel() }} &mdash;
                                            <strong>{{ $appointment->user?->name ?? 'Unknown client' }}</strong>
                                            ({{ $appointment->service?->name }})
                                            <span class="font-mono text-xs">{{ $appointment->reference_number }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="mt-2 text-xs text-amber-800">
                                    These appointments still exist. Contact or rebook each client.
                                </p>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    @endif

    @if ($showPast && $past->isNotEmpty())
        <div class="mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-6">
            <h2 class="text-lg font-bold text-slate-900">
                Past closures
                <span class="ml-1 text-sm font-normal text-slate-500">({{ $past->count() }})</span>
            </h2>

            <a href="{{ route('admin.holidays.index', array_filter(['seed_year' => $seedYear])) }}"
               class="text-sm font-medium text-slate-600 hover:text-slate-900 hover:underline">
                Hide past closures
            </a>
        </div>

        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Kept as a record of days the office was shut. Booking only ever considers closures from
            today onward, so these hide no slots and no client is affected &mdash; but they are
            <strong>permanent</strong>. A past closure cannot be removed: slot generation reads every
            closure with no date filter, so dropping one would let slots be created for a day the
            office was shut. Changes made while a closure was still in the future are recorded in the
            <a href="{{ route('admin.audit.index', ['action' => 'holiday.']) }}"
               class="font-medium text-blue-700 hover:underline">audit trail</a>.
        </p>

        {{--
        Past closures collapse by year, newest first, all closed.

        This list grows ~12 rows a year, forever, with nothing ever removed from
        it, and it sits below the fold - so every year it stole more space from
        the upcoming list above, which is the part anyone acts on. Grouped into
        year summaries it is a handful of lines no matter how far back it goes,
        and nothing is paginated away or deleted: the record is all still there,
        one click per year.

        A year opens on demand rather than by default. There is no reason to
        expand five years of history to find one date, and leaving them open
        would recreate the very wall this replaces.
    --}}
    @foreach ($past->groupBy(fn ($holiday) => $holiday->date->year) as $year => $rows)
        <details class="mt-2 rounded-xl border border-slate-200 bg-slate-50">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 [&::-webkit-details-marker]:hidden">
                <span class="text-sm font-semibold uppercase tracking-wide text-slate-600">{{ $year }}</span>
                <span class="text-sm font-normal text-slate-500">
                    {{ $rows->count() }} closure{{ $rows->count() === 1 ? '' : 's' }}
                </span>
            </summary>

            <ul class="space-y-2 border-t border-slate-200 p-3">
                @foreach ($rows as $holiday)
                    {{-- No Remove button. The controller refuses the delete anyway,
                         so showing a dead control beside a date that is on record
                         as shut would only invite a pointless round trip. --}}
                    <li class="rounded-lg border border-slate-200 bg-white p-3">
                        <p class="font-semibold text-slate-700">
                            {{ $holiday->name }}
                            <span class="ml-1 font-normal text-slate-500">
                                {{ $holiday->date->format('l, j F Y') }}
                            </span>
                        </p>

                        @if ($holiday->notes)
                            <p class="mt-1 text-sm text-slate-500">{{ $holiday->notes }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endforeach
    @endif
@endsection