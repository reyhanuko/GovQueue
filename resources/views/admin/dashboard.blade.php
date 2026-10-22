@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Dashboard</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            How the office is running today, and whether anything needs a decision.
        </p>
    </div>

    {{--
        Fix #6: Consistent section heading system.
        Small-caps label  → section groups a set of cards / live figures (no page nav needed)
        Large bold h2     → section has a table or list that deserves prominence
        All "Needs attention" items keep their amber/rose prominence.
    --}}

    {{-- ── Right now ──────────────────────────────────────────────────────── --}}
    <section class="mt-6" aria-labelledby="right-now">
        <h2 id="right-now" class="text-xs font-semibold uppercase tracking-widest text-slate-500">
            Right now
        </h2>

        <dl class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
            {{--
                Fix #1: each live stat gets its own colour when > 0.
                  Waiting     → amber  (queue is building — act soon)
                  Serving     → blue   (active transaction)
                  Completed   → emerald (positive outcome)
                  Upcoming    → slate  (informational only)

                Fix #2: sub-caption gives context for each number.
            --}}
            <div @class([
                'rounded-xl border-2 p-4 bg-white',
                'border-amber-300'  => $statusCounts['waiting'] > 0,
                'border-slate-200'  => $statusCounts['waiting'] === 0,
            ])>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Waiting now</dt>
                <dd @class([
                    'mt-1 text-3xl font-bold tabular-nums',
                    'text-amber-600'  => $statusCounts['waiting'] > 0,
                    'text-slate-400'  => $statusCounts['waiting'] === 0,
                ])>{{ $statusCounts['waiting'] }}</dd>
                <p class="mt-1 text-xs text-slate-500">In the queue today</p>
            </div>

            <div @class([
                'rounded-xl border-2 p-4 bg-white',
                'border-blue-300'  => $statusCounts['serving'] > 0,
                'border-slate-200' => $statusCounts['serving'] === 0,
            ])>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Being served</dt>
                <dd @class([
                    'mt-1 text-3xl font-bold tabular-nums',
                    'text-blue-600'  => $statusCounts['serving'] > 0,
                    'text-slate-400' => $statusCounts['serving'] === 0,
                ])>{{ $statusCounts['serving'] }}</dd>
                <p class="mt-1 text-xs text-slate-500">At a window right now</p>
            </div>

            <div @class([
                'rounded-xl border-2 p-4 bg-white',
                'border-emerald-300' => $statusCounts['completed'] > 0,
                'border-slate-200'   => $statusCounts['completed'] === 0,
            ])>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Completed today</dt>
                <dd @class([
                    'mt-1 text-3xl font-bold tabular-nums',
                    'text-emerald-600' => $statusCounts['completed'] > 0,
                    'text-slate-400'   => $statusCounts['completed'] === 0,
                ])>{{ $statusCounts['completed'] }}</dd>
                <p class="mt-1 text-xs text-slate-500">
                    Finished today
                    @if ($statusCounts['cancelled'] > 0)
                        · {{ $statusCounts['cancelled'] }} cancelled
                    @endif
                </p>
            </div>

            <div class="rounded-xl border-2 border-slate-200 bg-white p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Upcoming bookings</dt>
                <dd class="mt-1 text-3xl font-bold tabular-nums text-slate-900">{{ $upcoming }}</dd>
                <p class="mt-1 text-xs text-slate-500">From {{ $today->format('j M') }} onward</p>
            </div>
        </dl>
    </section>

    {{-- ── Setup ───────────────────────────────────────────────────────────── --}}
    <section class="mt-6" aria-labelledby="setup">
        <h2 id="setup" class="text-xs font-semibold uppercase tracking-widest text-slate-500">
            Setup &mdash; changes only when you make them
        </h2>

        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{--
                Fix #3: Zero-count cards get a red warning badge so a broken
                configuration is immediately obvious, not hidden as just a "0".
                Holidays at zero is fine (no holidays coming up = good news).
            --}}
            @foreach ([
                ['Active windows',   $counts['windows'],  route('admin.windows.index'),  true],
                ['Active services',  $counts['services'], route('admin.services.index'),  true],
                ['Active staff',     $counts['staff'],    route('admin.staff.index'),     true],
                ['Upcoming holidays',$counts['holidays'], route('admin.holidays.index'),  false],
            ] as [$label, $value, $link, $warnOnZero])
                <a href="{{ $link }}"
                   class="group rounded-xl border bg-white p-4 transition-colors hover:border-blue-400 hover:bg-blue-50/40
                          {{ ($warnOnZero && $value === 0) ? 'border-rose-300 bg-rose-50/30' : 'border-slate-200' }}">
                    <span class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide
                                     {{ ($warnOnZero && $value === 0) ? 'text-rose-600' : 'text-slate-500' }}">
                            {{ $label }}
                        </span>
                        @if ($warnOnZero && $value === 0)
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">
                                None set
                            </span>
                        @endif
                    </span>
                    <span class="mt-1 block text-2xl font-bold tabular-nums
                                 {{ ($warnOnZero && $value === 0) ? 'text-rose-600' : 'text-slate-900' }}">
                        {{ $value }}
                    </span>
                    <span class="mt-1 block text-xs font-medium text-blue-700 opacity-0 transition-opacity group-hover:opacity-100">
                        Manage &rarr;
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    {{-- ── Needs attention ─────────────────────────────────────────────────── --}}
    @if ($missingTemplates->isNotEmpty() || $holidayConflicts->isNotEmpty() || ! $realtimeHealthy)
        <section class="mt-8" aria-labelledby="needs-attention">
            <h2 id="needs-attention" class="text-xs font-semibold uppercase tracking-widest text-slate-500">
                Needs attention
            </h2>

            <ul class="mt-3 space-y-3">
                @foreach ($holidayConflicts as $conflict)
                    <li class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                        <p class="font-semibold text-amber-900">
                            {{ $conflict['count'] }} client(s) hold a booking on
                            {{ $conflict['holiday']->date->format('j M Y') }}
                            &mdash; {{ $conflict['holiday']->name }}
                        </p>
                        <p class="mt-1 text-sm text-amber-800">
                            The office is closed that day, so those slots are hidden from every booking
                            screen. The appointments were <strong>not</strong> cancelled automatically
                            &mdash; contact or rebook each client.
                        </p>
                        <a href="{{ route('admin.holidays.index') }}"
                           class="mt-2 inline-block text-sm font-semibold text-amber-900 underline">
                            Review affected clients
                        </a>
                    </li>
                @endforeach

                @foreach ($missingTemplates as $service)
                    <li class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                        <p class="font-semibold text-amber-900">
                            {{ $service->name }} has no bookable slots
                        </p>
                        <p class="mt-1 text-sm text-amber-800">
                            It has no active slot template, and no generated slots in the next
                            {{ $horizon }} days, so clients see this service but cannot book it.
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-4">
                            <a href="{{ route('admin.templates.index', $service) }}"
                               class="text-sm font-semibold text-amber-900 underline">
                                Add a slot template
                            </a>
                            {{-- Fix #4: cross-link to rebuild so the admin knows the next step. --}}
                            <span class="text-xs text-amber-700">
                                Then use <strong>Rebuild slots</strong> below to generate the dates.
                            </span>
                        </div>
                    </li>
                @endforeach

                @unless ($realtimeHealthy)
                    <li class="rounded-xl border border-rose-300 bg-rose-50 p-4">
                        <p class="font-semibold text-rose-900">The lobby display is not updating</p>
                        <p class="mt-1 text-sm text-rose-800">
                            Broadcasting failed recently, so queue changes are not reaching the display.
                            Booking still works. Start the Reverb server and the updates resume on their own.
                        </p>
                    </li>
                @endunless
            </ul>
        </section>
    @endif

    {{-- ── Windows + Recent changes ────────────────────────────────────────── --}}
    <div class="mt-8 grid gap-6 lg:grid-cols-2">

        <section aria-labelledby="windows-heading">
            <h2 id="windows-heading" class="text-xs font-semibold uppercase tracking-widest text-slate-500">
                Service windows
            </h2>
            <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Service windows with their service and staff counts</caption>
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2">Window</th>
                            <th scope="col" class="px-4 py-2">Services</th>
                            <th scope="col" class="px-4 py-2">Staff</th>
                            <th scope="col" class="px-4 py-2">State</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($windows as $window)
                            <tr class="group hover:bg-blue-50/40">
                                <th scope="row" class="px-4 py-2 text-left font-medium">
                                    <a href="{{ route('admin.windows.edit', $window) }}"
                                       class="block rounded text-slate-900 group-hover:text-blue-800 group-hover:underline">
                                        {{ $window->name }}
                                        <span class="block font-mono text-xs font-normal text-slate-500">{{ $window->code }}</span>
                                    </a>
                                </th>
                                <td class="px-4 py-2 tabular-nums">{{ $window->services_count }}</td>
                                <td class="px-4 py-2 tabular-nums">
                                    @if ($window->staff_count === 0)
                                        <span class="font-semibold text-rose-600">0</span>
                                    @else
                                        {{ $window->staff_count }}
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if ($window->is_active)
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-900">Active</span>
                                    @else
                                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center">
                                    <svg class="mx-auto h-8 w-8 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>
                                    </svg>
                                    <p class="mt-2 text-sm font-medium text-slate-800">No service windows yet</p>
                                    <p class="mx-auto mt-1 max-w-sm text-xs text-slate-500">
                                        A window is a counter clients queue at, such as "Certificates".
                                        Everything else &mdash; services, slots, staff &mdash; hangs off one,
                                        so this is the first thing to create.
                                    </p>
                                    <a href="{{ route('admin.windows.create') }}"
                                       class="mt-3 inline-block rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                                        Create the first window
                                    </a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="recent-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="recent-heading" class="text-xs font-semibold uppercase tracking-widest text-slate-500">
                    Recent changes
                </h2>
                <a href="{{ route('admin.audit.index') }}" class="text-xs font-medium text-blue-700 hover:underline">
                    Full audit trail &rarr;
                </a>
            </div>

            <ol class="mt-3 space-y-2">
                @forelse ($recentAudit as $entry)
                    <li class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm">
                        <span class="font-mono text-xs text-slate-500">{{ $entry->created_at->format('j M H:i') }}</span>
                        <span class="ml-1.5 font-medium text-slate-900">{{ $entry->actionSentence() }}</span>
                        <span class="text-slate-600">
                            @if ($entry->subjectLabel() !== '—')
                                &mdash; {{ $entry->subjectLabel() }}
                            @endif
                            by {{ $entry->user?->name ?? 'the system' }}
                        </span>
                    </li>
                @empty
                    {{-- Fix #5: empty state with an icon. --}}
                    <li class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center">
                        <svg class="mx-auto h-8 w-8 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>
                        </svg>
                        <p class="mt-2 text-sm font-medium text-slate-700">Nothing recorded yet</p>
                        <p class="mx-auto mt-1 max-w-xs text-xs text-slate-500">
                            Anything that changes the schedule, a booking or an account is written here
                            as it happens, so you can confirm a change took effect without asking.
                        </p>
                    </li>
                @endforelse
            </ol>
        </section>
    </div>

    {{-- ── Schedule maintenance ────────────────────────────────────────────── --}}
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
                          @js($rebuild['templates'].' active '.Illuminate\Support\Str::plural('template', $rebuild['templates']).' will be expanded over the next '.request()->integer('days', $horizon).' days, bringing '.Illuminate\Support\Str::plural('slot', $rebuild['existing']).' in line. Your '.Illuminate\Support\Str::plural('booking', $rebuild['booked']).' are not affected.')
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
