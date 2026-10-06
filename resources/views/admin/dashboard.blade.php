@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
    @include('admin.partials.nav')

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Administration</h1>
            <p class="mt-1 text-sm text-slate-600">
                Configure windows, services, slot capacity, holidays, and staff accounts.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.regenerate') }}"
              class="flex flex-wrap items-end gap-2">
            @csrf
            <div>
                <label for="days" class="block text-xs font-medium text-slate-700">Regenerate slots for</label>
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
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                Rebuild slots
            </button>
        </form>
    </div>

    {{-- Today at a glance --}}
    <h2 class="sr-only">Today at a glance</h2>
    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            ['Waiting now', $statusCounts['waiting'], 'Clients in the queue today'],
            ['Being served', $statusCounts['serving'], 'Currently at a window'],
            ['Upcoming bookings', $upcoming, 'Confirmed appointments from '.$today->format('j M Y').' onward'],
            ['Completed today', $statusCounts['completed'], 'Finished today'],
        ] as [$label, $value, $caption])
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <dt class="text-sm font-medium text-slate-600">{{ $label }}</dt>
                <dd class="mt-1 text-3xl font-bold text-slate-900">{{ $value }}</dd>
                <p class="mt-1 text-xs text-slate-500">{{ $caption }}</p>
            </div>
        @endforeach
    </dl>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Active windows', $counts['windows'], route('admin.windows.index')],
            ['Active services', $counts['services'], route('admin.services.index')],
            ['Active staff', $counts['staff'], route('admin.staff.index')],
            ['Upcoming holidays', $counts['holidays'], route('admin.holidays.index')],
        ] as [$label, $value, $link])
            <a href="{{ $link }}"
               class="rounded-xl border border-slate-200 bg-white p-4 hover:border-blue-300 hover:bg-blue-50/40">
                <span class="text-sm font-medium text-slate-600">{{ $label }}</span>
                <span class="mt-1 block text-2xl font-bold text-slate-900">{{ $value }}</span>
                <span class="mt-1 block text-xs font-medium text-blue-700">Manage &rarr;</span>
            </a>
        @endforeach
    </div>

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
                        <a href="{{ route('admin.templates.index', $service) }}"
                           class="mt-2 inline-block text-sm font-semibold text-amber-900 underline">
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
                            <tr>
                                <th scope="row" class="px-4 py-2 text-left font-medium text-slate-800">
                                    {{ $window->name }}
                                    <span class="block font-mono text-xs font-normal text-slate-500">{{ $window->code }}</span>
                                </th>
                                <td class="px-4 py-2">{{ $window->services_count }}</td>
                                <td class="px-4 py-2">{{ $window->staff_count }}</td>
                                <td class="px-4 py-2">
                                    @if ($window->is_active)
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                                    @else
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-slate-500">
                                    No service windows yet.
                                    <a href="{{ route('admin.windows.create') }}" class="text-blue-700 underline">Create the first one</a>.
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
                <a href="{{ route('admin.audit.index') }}" class="text-sm font-medium text-blue-700 hover:underline">
                    Full audit trail &rarr;
                </a>
            </div>

            <ol class="mt-3 space-y-2">
                @forelse ($recentAudit as $entry)
                    <li class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm">
                        <span class="font-mono text-xs text-slate-500">{{ $entry->created_at->format('j M H:i') }}</span>
                        <span class="font-medium text-slate-800">{{ $entry->action }}</span>
                        <span class="text-slate-600">
                            by {{ $entry->user?->name ?? 'the system' }}
                        </span>
                    </li>
                @empty
                    <li class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">
                        Nothing recorded yet.
                    </li>
                @endforelse
            </ol>
        </section>
    </div>
@endsection
