@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div>
        {{-- The heading now says what the screen is, matching both the nav item
             and the browser tab. It read "Administration" here and "Dashboard"
             in the sidebar, so neither could be quoted to another person. --}}
        <h1 class="text-2xl font-bold text-slate-900">Dashboard</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            How the office is running today, and whether anything needs a decision.
        </p>
    </div>

    {{--
        Live figures and configuration counts are kept apart on purpose.

        They were two identical four-column grids of identical cards, so "3
        clients waiting" and "3 services configured" looked like the same kind of
        fact. One is what is happening now and changes by itself; the other is
        what somebody has to go and edit. Different urgency, so they now look
        different.
    --}}
    <section class="mt-6" aria-labelledby="right-now">
        <h2 id="right-now" class="text-sm font-semibold uppercase tracking-wide text-slate-600">
            Right now
        </h2>

        <dl class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['Waiting now', $statusCounts['waiting'], 'In the queue today'],
                ['Being served', $statusCounts['serving'], 'At a window right now'],
                ['Upcoming bookings', $upcoming, 'From '.$today->format('j M Y').' onward'],
                ['Completed today', $statusCounts['completed'], 'Finished today'],
            ] as [$label, $value, $caption])
                <div class="rounded-xl border-2 border-slate-300 bg-white p-4">
                    <dt class="text-sm font-medium text-slate-700">{{ $label }}</dt>
                    <dd @class([
                        'mt-1 text-3xl font-bold',
                        // A non-empty queue is the one figure here worth noticing.
                        'text-emerald-700' => $label === 'Waiting now' && $value > 0,
                        'text-slate-900' => ! ($label === 'Waiting now' && $value > 0),
                    ])>{{ $value }}</dd>
                    <p class="mt-1 text-xs text-slate-600">{{ $caption }}</p>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="mt-6" aria-labelledby="setup">
        <h2 id="setup" class="text-sm font-semibold uppercase tracking-wide text-slate-600">
            Setup &mdash; changes only when you make them
        </h2>

        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Active windows', $counts['windows'], route('admin.windows.index')],
                ['Active services', $counts['services'], route('admin.services.index')],
                ['Active staff', $counts['staff'], route('admin.staff.index')],
                ['Upcoming holidays', $counts['holidays'], route('admin.holidays.index')],
            ] as [$label, $value, $link])
                <a href="{{ $link }}"
                   class="rounded-xl border border-slate-200 bg-white p-4 hover:border-blue-400 hover:bg-blue-50/40">
                    <span class="text-sm font-medium text-slate-600">{{ $label }}</span>
                    <span class="mt-1 block text-2xl font-bold text-slate-900">{{ $value }}</span>
                    <span class="mt-1 block text-xs font-medium text-blue-700">Manage &rarr;</span>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Things that need a human decision. These are the reason this screen exists. --}}
    @if ($missingTemplates->isNotEmpty() || $holidayConflicts->isNotEmpty() || ! $realtimeHealthy)
        <section class="mt-8" aria-labelledby="needs-attention">
            <h2 id="needs-attention" class="text-lg font-bold text-slate-900">Needs attention</h2>

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
                        {{-- py-1 so the link is a target of its own: at text-sm
                             it is 20 pixels tall. --}}
                        <a href="{{ route('admin.templates.index', $service) }}"
                           class="mt-2 inline-block py-1 text-sm font-semibold text-amber-900 underline">
                            Add a slot template
                        </a>
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

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="windows-heading">
            <h2 id="windows-heading" class="text-lg font-bold text-slate-900">Service windows</h2>
            <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Service windows with their service and staff counts</caption>
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-2">Window</th>
                            <th scope="col" class="px-4 py-2">Services</th>
                            <th scope="col" class="px-4 py-2">Staff</th>
                            <th scope="col" class="px-4 py-2">State</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($windows as $window)
                            {{-- The whole row goes to the window's edit form. These rows
                                 had no action at all, so seeing "2 services, 0 staff"
                                 gave you nothing to act on and no way to get there from
                                 here. --}}
                            <tr class="group hover:bg-blue-50/40">
                                <th scope="row" class="px-4 py-2 text-left font-medium">
                                    <a href="{{ route('admin.windows.edit', $window) }}"
                                       class="block rounded text-slate-900 group-hover:text-blue-800 group-hover:underline">
                                        {{ $window->name }}
                                        <span class="block font-mono text-xs font-normal text-slate-600">{{ $window->code }}</span>
                                    </a>
                                </th>
                                <td class="px-4 py-2">{{ $window->services_count }}</td>
                                <td class="px-4 py-2">{{ $window->staff_count }}</td>
                                <td class="px-4 py-2">
                                    @if ($window->is_active)
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-900">Active</span>
                                    @else
                                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center">
                                    <p class="text-sm font-medium text-slate-800">No service windows yet</p>
                                    <p class="mx-auto mt-1 max-w-sm text-xs text-slate-600">
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
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="recent-heading" class="text-lg font-bold text-slate-900">Recent changes</h2>
                {{-- py-1 so the link is a target of its own: at text-sm it is
                     20 pixels tall. --}}
                <a href="{{ route('admin.audit.index') }}" class="py-1 text-sm font-medium text-blue-700 hover:underline">
                    Full audit trail &rarr;
                </a>
            </div>

            <ol class="mt-3 space-y-2">
                @forelse ($recentAudit as $entry)
                    {{-- A sentence, not the stored key. The raw action is still
                         what the audit screen filters on; it was never meant to be
                         the thing an administrator reads while checking whether
                         their change took effect. --}}
                    <li class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm">
                        <span class="font-mono text-xs text-slate-600">{{ $entry->created_at->format('j M H:i') }}</span>
                        <span class="font-medium text-slate-900">{{ $entry->actionSentence() }}</span>
                        <span class="text-slate-700">
                            @if ($entry->subjectLabel() !== '—')
                                &mdash; {{ $entry->subjectLabel() }}
                            @endif
                            by {{ $entry->user?->name ?? 'the system' }}
                        </span>
                    </li>
                @empty
                    <li class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center">
                        <p class="text-sm font-medium text-slate-700">Nothing recorded yet</p>
                        <p class="mx-auto mt-1 max-w-sm text-xs text-slate-600">
                            Anything that changes the schedule, a booking or an account is written here
                            as it happens, so you can confirm a change took effect without asking.
                        </p>
                    </li>
                @endforelse
            </ol>
        </section>
    </div>

    {{--
        Rebuild slots.

        This was the largest, bluest button on the screen, at the top, with no
        explanation of what it touched. It is a maintenance action that rewrites
        the bookable calendar, so it now says what it does, says how much it
        covers, and confirms before running. It is also no longer styled as the
        primary call to action, because most visits to this screen are about
        reading the state of the office, not changing it.
    --}}
    <section class="mt-8 rounded-xl border border-slate-200 bg-white p-5" aria-labelledby="rebuild-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <h2 id="rebuild-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-600">
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
                    <label for="days" class="block text-xs font-medium text-slate-700">Cover the next</label>
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
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                    Rebuild slots
                </button>
            </form>
        </div>
    </section>
@endsection
