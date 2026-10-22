@extends('layouts.app')

@section('title', 'Holidays & Closures')

@section('content')
    {{-- Breadcrumb --}}
    <nav aria-label="Breadcrumb" class="mb-4">
        <ol class="flex flex-wrap items-center gap-x-1 gap-y-1 text-sm text-slate-500">
            <li>
                <a href="{{ route('admin.dashboard') }}"
                   class="hover:text-slate-700 hover:underline">Dashboard</a>
            </li>
            <li aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 text-slate-400">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                </svg>
            </li>
            <li class="font-medium text-slate-900">Holidays &amp; Closures</li>
        </ol>
    </nav>

    <h1 class="text-2xl font-bold text-slate-900">Holidays and closures</h1>
    <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Declaring a holiday hides that date&rsquo;s slots from every booking screen. It does
        <strong>not</strong> cancel appointments already booked &mdash; any affected clients are listed
        against their closure for someone to contact or rebook.
    </p>

    {{--
        Order matters here, and it is a compromise between two things that pull
        in opposite directions.

        "Declare a closure" is this screen's primary verb, so it stays at the top
        where the action is discoverable. The seed form collapses to one summary
        line because seeding a year is a bulk import someone runs once, not a
        daily task. What is left above the list is one compact grid row plus one
        line, which keeps the closures close to the top without hiding the action
        behind a click.

        Native <details> rather than a JS toggle: it works without script, is
        keyboard operable for free, and matches the disclosure the navbar uses.
        It force-opens when its own form is showing a validation error, so a
        rejected submit does not leave the offending field hidden in a closed box.
    --}}

    {{-- ─── Declare a closure ────────────────────────────────────────────── --}}
    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
             aria-labelledby="add-holiday-heading">
        <h2 id="add-holiday-heading"
            class="text-xs font-semibold uppercase tracking-widest text-slate-400">
            Declare a closure
        </h2>

        <form method="POST" action="{{ route('admin.holidays.store') }}"
              class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @csrf

            <x-admin.field name="date" type="date" label="Date" required
                          :value="old('date', today()->toDateString())"
                          :min="today()->toDateString()"
                          help="Today or later. A date already past cannot be booked." />

            <x-admin.field name="name" label="Name" required
                          :value="old('name')" placeholder="Foundational Day" />

            <div class="sm:col-span-2">
                <x-admin.field name="notes" label="Notes" :value="old('notes')"
                              placeholder="Optional — e.g. local city holiday." />
            </div>

            <div class="flex items-end">
                <button type="submit"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Save closure
                </button>
            </div>
        </form>
    </section>

    {{-- ─── Add national holidays (collapsible) ───────────────────────────── --}}
    <details class="group mt-4 rounded-xl border border-slate-200 bg-white open:shadow-sm"
             @if ($errors->has('year')) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3
                        rounded-xl px-6 py-4 [&::-webkit-details-marker]:hidden">
            <div>
                <span class="text-sm font-semibold text-slate-800">Add national holidays for a year</span>
                <span class="ml-2 text-xs text-slate-500">Seed a whole year in one go</span>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                 class="h-5 w-5 flex-shrink-0 text-slate-400 transition-transform duration-200 group-open:rotate-180">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
        </summary>

        <div class="border-t border-slate-100 px-6 pb-6 pt-4">
            <p class="text-sm text-slate-600">
                Declares the regular Philippine national holidays for a year in one go, instead of
                typing each date. Dates you have already declared are left exactly as they are, so
                this is safe to run more than once and safe to run after hand-adding a local closure.
            </p>

            <form method="POST" action="{{ route('admin.holidays.seed') }}"
                  class="mt-4 flex flex-wrap items-end gap-3">
                @csrf

                <div class="min-w-[10rem]">
                    <label for="seed-year" class="block text-xs font-medium text-slate-700">Year</label>
                    <input id="seed-year" name="year" type="number" required
                           value="{{ old('year', $seedYear) }}"
                           min="{{ now()->year }}" max="2100"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                                  focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500
                                  @error('year') border-rose-400 @enderror">
                    @error('year')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold
                               text-slate-700 hover:bg-slate-50">
                    Add national holidays
                </button>
            </form>

            <p class="mt-3 text-xs text-slate-500">
                Covers the fixed-date regular holidays plus Maundy Thursday and Good Friday, which
                follow Easter. Eid&rsquo;l Fitr and Eid&rsquo;l Adha are lunar and are not included &mdash; a guessed
                date could close the office on a day it is open. Add those by hand from the proclamation.
                A year that has already passed cannot be seeded.
            </p>
        </div>
    </details>

    {{-- ─── Upcoming closures ─────────────────────────────────────────────── --}}
    <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xs font-semibold uppercase tracking-widest text-slate-400">
                Upcoming closures
            </h2>
            <p class="mt-0.5 text-sm font-semibold text-slate-900">
                {{ $upcoming->count() }} {{ Str::plural('closure', $upcoming->count()) }} scheduled
            </p>
        </div>

        @if ($past->isNotEmpty())
            <a href="{{ route('admin.holidays.index', array_filter(['show_past' => 1, 'seed_year' => $seedYear])) }}"
               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white
                      px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm
                      hover:bg-slate-50 hover:text-slate-900">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5">
                    <path fill-rule="evenodd" d="M1 8a7 7 0 1 1 14 0A7 7 0 0 1 1 8Zm7.75-4.25a.75.75 0 0 0-1.5 0V8c0 .414.336.75.75.75h3.25a.75.75 0 0 0 0-1.5h-2.5V3.75Z" clip-rule="evenodd" />
                </svg>
                Show {{ $past->count() }} past {{ Str::plural('closure', $past->count()) }}
            </a>
        @endif
    </div>

    @if ($upcoming->isEmpty())
        <div class="mt-3 flex flex-col items-center justify-center rounded-xl border border-dashed
                    border-slate-300 bg-white px-4 py-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.5" stroke="currentColor" class="mb-3 h-10 w-10 text-slate-300">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" />
            </svg>
            <p class="text-sm font-medium text-slate-600">No upcoming closures</p>
            <p class="mt-1 text-xs text-slate-400">
                Clients can book every day the slot templates cover.
            </p>
        </div>
    @else
        {{-- Grouped by year so a seeded calendar reads as a handful of headings
             rather than a wall of individual rows. --}}
        @foreach ($upcoming->groupBy(fn ($holiday) => $holiday->date->year) as $year => $rows)
            <h3 class="mt-6 text-xs font-semibold uppercase tracking-widest text-slate-400">
                {{ $year }}
                <span class="ml-1 font-normal normal-case text-slate-400">({{ $rows->count() }})</span>
            </h3>

            <ul class="mt-2 space-y-2">
                @foreach ($rows as $holiday)
                    @php
                        $booked  = $affected[$holiday->id] ?? collect();
                        $isToday = $holiday->date->isToday();
                    @endphp

                    <li class="overflow-hidden rounded-xl border border-slate-200 bg-white
                                transition-shadow hover:shadow-sm">

                        {{-- Card header row --}}
                        <div class="flex flex-wrap items-start gap-4 p-4">

                            {{-- Calendar date chip --}}
                            <div class="flex min-w-[3.25rem] flex-col items-center rounded-lg
                                        {{ $isToday ? 'bg-indigo-600' : 'bg-slate-100' }} px-2.5 py-1.5 text-center">
                                <span class="text-[10px] font-bold uppercase tracking-widest
                                             {{ $isToday ? 'text-indigo-200' : 'text-slate-500' }}">
                                    {{ $holiday->date->format('M') }}
                                </span>
                                <span class="text-xl font-bold leading-none
                                             {{ $isToday ? 'text-white' : 'text-slate-900' }}">
                                    {{ $holiday->date->format('d') }}
                                </span>
                                <span class="text-[10px] {{ $isToday ? 'text-indigo-200' : 'text-slate-500' }}">
                                    {{ $holiday->date->format('D') }}
                                </span>
                            </div>

                            {{-- Holiday details --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-semibold text-slate-900">{{ $holiday->name }}</p>

                                    @if ($isToday)
                                        <span class="rounded-full bg-indigo-100 px-2 py-0.5
                                                     text-xs font-semibold text-indigo-700">Today</span>
                                    @endif

                                    @if ($booked->isNotEmpty())
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5
                                                     text-xs font-semibold text-amber-700">
                                            {{ $booked->count() }} {{ Str::plural('booking', $booked->count()) }} affected
                                        </span>
                                    @endif
                                </div>

                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $holiday->date->format('l, j F Y') }}
                                </p>

                                @if ($holiday->notes)
                                    <p class="mt-1 text-sm text-slate-600">{{ $holiday->notes }}</p>
                                @endif
                            </div>

                            {{-- Remove button --}}
                            <form method="POST" action="{{ route('admin.holidays.destroy', $holiday) }}"
                                  onsubmit="return window.govqueueConfirm
                                      ? window.govqueueConfirm(this, 'Remove this closure?',
                                          {!! e(json_encode('Clients will be able to book '.$holiday->date->format('j F Y').' again. Any slots on that date will become bookable.')) !!})
                                      : confirm('Remove this closure? Clients will be able to book that date again.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="rounded-lg border border-rose-200 px-3 py-1.5
                                               text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                    Remove
                                </button>
                            </form>
                        </div>

                        {{-- Affected-clients warning (only when bookings exist) --}}
                        @if ($booked->isNotEmpty())
                            <div class="border-t border-amber-100 bg-amber-50 px-4 py-4">
                                <p class="text-sm font-semibold text-amber-900">
                                    {{ $booked->count() }} {{ Str::plural('client', $booked->count()) }}
                                    still hold a booking on this date
                                </p>

                                <ul class="mt-2 space-y-1.5">
                                    @foreach ($booked as $appointment)
                                        <li class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-amber-900">
                                            <span class="font-medium">{{ $appointment->scheduledTimeLabel() }}</span>
                                            <span class="text-amber-600">&mdash;</span>
                                            <strong>{{ $appointment->user?->name ?? 'Unknown client' }}</strong>
                                            <span class="text-amber-700">({{ $appointment->service?->name }})</span>
                                            <span class="font-mono text-xs text-amber-600">{{ $appointment->reference_number }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                <p class="mt-2 text-xs text-amber-700">
                                    These appointments still exist. Contact or rebook each client, or
                                    cancel all at once and each client will be notified automatically.
                                </p>

                                <form method="POST"
                                      action="{{ route('admin.holidays.cancel-affected', $holiday) }}"
                                      class="mt-3"
                                      onsubmit="return window.govqueueConfirm
                                          ? window.govqueueConfirm(this, 'Cancel all bookings on this date?',
                                              {!! e(json_encode($booked->count().' '.Str::plural('booking', $booked->count()).' will be cancelled and each client notified that the office is closed that day. The places return to the pool — clients will need to rebook.')) !!})
                                          : confirm('Cancel all bookings on this date?');">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-lg bg-amber-600 px-3 py-1.5
                                                   text-xs font-semibold text-white hover:bg-amber-700">
                                        Cancel all {{ $booked->count() }} and notify clients
                                    </button>
                                    <span class="ml-2 text-xs text-amber-700">
                                        or contact and rebook them yourself
                                    </span>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    @endif

    {{-- ─── Past closures ─────────────────────────────────────────────────── --}}
    @if ($showPast && $past->isNotEmpty())
        <div class="mt-10 flex flex-wrap items-center justify-between gap-3
                    border-t border-slate-200 pt-6">
            <div>
                <h2 class="text-xs font-semibold uppercase tracking-widest text-slate-400">
                    Past closures
                </h2>
                <p class="mt-0.5 text-sm font-semibold text-slate-900">
                    {{ $past->count() }} {{ Str::plural('closure', $past->count()) }} on record
                </p>
            </div>

            <a href="{{ route('admin.holidays.index', array_filter(['seed_year' => $seedYear])) }}"
               class="text-sm font-medium text-slate-500 hover:text-slate-700 hover:underline">
                Hide past closures
            </a>
        </div>

        <p class="mt-2 max-w-3xl text-sm text-slate-600">
            Kept as a permanent record of days the office was shut. Booking only ever considers closures
            from today onward, so these hide no slots and affect no client &mdash; but they are
            <strong>permanent</strong>: a past closure cannot be removed, because removing it would allow
            slots to be generated for a day the office was recorded as shut. Changes made while the
            closure was still in the future are in the
            <a href="{{ route('admin.audit.index', ['action' => 'holiday.']) }}"
               class="font-medium text-indigo-600 hover:underline">audit trail</a>.
        </p>

        {{--
            Past closures collapse by year, newest first, all closed.

            This list grows ~12 rows a year, forever, with nothing ever removed from
            it. Grouped into year summaries it is a handful of lines no matter how
            far back it goes, and nothing is paginated away: the record is all still
            there, one click per year.
        --}}
        @foreach ($past->groupBy(fn ($holiday) => $holiday->date->year) as $year => $rows)
            <details class="group mt-2 rounded-xl border border-slate-200 bg-slate-50">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3
                                rounded-xl px-4 py-3 [&::-webkit-details-marker]:hidden">
                    <span class="text-xs font-semibold uppercase tracking-widest text-slate-500">
                        {{ $year }}
                    </span>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-400">
                            {{ $rows->count() }} {{ Str::plural('closure', $rows->count()) }}
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                             class="h-4 w-4 text-slate-400 transition-transform duration-200 group-open:rotate-180">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </summary>

                <ul class="space-y-2 border-t border-slate-200 p-3">
                    @foreach ($rows as $holiday)
                        {{-- No Remove button: the controller refuses past deletes, so a dead
                             button beside a permanently-recorded date only invites confusion. --}}
                        <li class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-3">
                            <div class="flex min-w-[2.75rem] flex-col items-center rounded-md
                                        bg-slate-100 px-2 py-1 text-center">
                                <span class="text-[9px] font-bold uppercase tracking-widest text-slate-400">
                                    {{ $holiday->date->format('M') }}
                                </span>
                                <span class="text-base font-bold leading-none text-slate-600">
                                    {{ $holiday->date->format('d') }}
                                </span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-700">{{ $holiday->name }}</p>
                                <p class="text-xs text-slate-500">{{ $holiday->date->format('l, j F Y') }}</p>
                                @if ($holiday->notes)
                                    <p class="mt-1 text-xs text-slate-400">{{ $holiday->notes }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endforeach
    @endif
@endsection