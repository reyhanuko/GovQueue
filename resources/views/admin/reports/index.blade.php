@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Reports</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                {{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}
                <span class="text-slate-600">({{ $days }} {{ $days === 1 ? 'day' : 'days' }})</span>
            </p>
        </div>

        {{--
            Both formats take the same period query string, so the download is
            always the report on screen. PDF is what gets printed and filed;
            CSV is what somebody sorts and totals.
        --}}
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.reports.export.pdf', $filters) }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Download PDF
            </a>
            <a href="{{ route('admin.reports.export', $filters) }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Download CSV
            </a>
        </div>
    </div>

    {{-- Period picker --}}
    <form method="GET" action="{{ route('admin.reports.index') }}"
          class="mt-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-4">
        <div>
            <label for="period" class="block text-xs font-medium text-slate-700">Quick range</label>
            <select id="period" name="period"
                    class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                @foreach ($presets as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['period'] ?? '30') === (string) $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        {{--
            min/max bound the pickers to the range that can hold data.

            Without them a mistyped year produced a silently empty report, and an
            empty report looks exactly like a quiet period - so the natural
            conclusion was that the queue had stopped working.
        --}}
        <div>
            <label for="from" class="block text-xs font-medium text-slate-700">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"
                   min="{{ $dateBounds[0] }}" max="{{ $dateBounds[1] }}"
                   class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="to" class="block text-xs font-medium text-slate-700">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"
                   min="{{ $dateBounds[0] }}" max="{{ $dateBounds[1] }}"
                   class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Apply
        </button>

        <p class="text-xs text-slate-500">
            Leave the dates blank to use the quick range.
        </p>
    </form>

    {{-- Headline figures --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">At a glance</h2>

    {{--
        The reconciliation.

        Reported as "numbers do not reconcile: Appointments 216, Completed 181,
        No-show 27, Cancelled 0". They did reconcile - 181 + 27 = 208, leaving 8
        tickets that were neither completed nor cancelled nor a no-show, because
        they had not been decided yet. But nothing on the page said that, and the
        only figure an administrator could add by hand came out 8 short of the
        total, so it read as a fault in the report rather than in the day.

        This block states the identity outright and shows the remainder, so
        "Skipped" is not mistaken for a fourth outcome that belongs in the sum.
        It is not: a skipped ticket can still be served, so it overlaps the
        completed figure and would double-count if added.
    --}}
    <div class="mt-3 rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-slate-900">How the {{ $summary['in_period'] }} appointments in this period break down</h3>

        <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                ['Completed', $summary['completed'], 'Served and closed.'],
                ['Not completed', $summary['not_completed'], 'Served, but the service could not be given.'],
                ['Cancelled', $summary['cancelled'], 'Withdrawn before the slot was served.'],
                ['No-show', $summary['no_show'], 'Client did not arrive.'],
                ['Still open', $summary['still_open'], 'Booked, waiting, checked in or mid-service. Not an outcome yet.'],
            ] as [$label, $value, $caption])
                <li class="rounded-lg bg-slate-50 px-4 py-3">
                    <p class="text-sm font-medium text-slate-800">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ $value }}</p>
                    <p class="mt-1 text-xs text-slate-600">{{ $caption }}</p>
                </li>
            @endforeach
        </ul>

        <p class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-700">
            {{ $summary['completed'] }} completed
            + {{ $summary['not_completed'] }} not completed
            + {{ $summary['cancelled'] }} cancelled
            + {{ $summary['no_show'] }} no-show
            + {{ $summary['still_open'] }} still open
            =
            <strong>{{ $summary['in_period'] }}</strong>
        </p>

        <p class="mt-2 text-xs text-slate-600">
            <strong>{{ $summary['skipped'] }}</strong>
            {{ \Illuminate\Support\Str::plural('ticket', $summary['skipped']) }}
            {{ \Illuminate\Support\Str::plural('was', $summary['skipped']) }} skipped at the counter.
            That is a separate quality measure and is <em>not</em> added to the row above - a
            skipped ticket can still be served, so it overlaps with completed.
        </p>
    </div>

    <dl class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <dt class="text-sm font-medium text-slate-600">Appointments in this period</dt>
            <dd class="mt-1 text-3xl font-bold text-slate-900">{{ $summary['in_period'] }}</dd>
            <p class="mt-1 text-xs text-slate-500">
                Tickets dated {{ $from->format('j M') }} to {{ $to->format('j M') }}
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <dt class="text-sm font-medium text-slate-600">Bookings made</dt>
            <dd class="mt-1 text-3xl font-bold text-slate-900">{{ $summary['bookings_made'] }}</dd>
            <p class="mt-1 text-xs text-slate-500">
                Clients who confirmed a slot during these dates, for any date.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <dt class="text-sm font-medium text-slate-600">Completed</dt>
            <dd class="mt-1 text-3xl font-bold text-slate-900">{{ $summary['completed'] }}</dd>
            <p class="mt-1 text-xs text-slate-500">Of the appointments in this period</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <dt class="text-sm font-medium text-slate-600">Cancelled</dt>
            <dd class="mt-1 text-3xl font-bold text-slate-900">{{ $summary['cancelled'] }}</dd>
            <p class="mt-1 text-xs text-slate-500">
                @if ($summary['cancellation_rate'] === null)
                    no decided appointments in this period
                @else
                    {{ $summary['cancellation_rate'] }}% of {{ $summary['cancellation_base'] }} decided
                @endif
            </p>
        </div>
    </dl>

    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <dt class="text-sm font-medium text-slate-600">Did not show up</dt>
            <dd class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['no_show'] }}</dd>
            <p class="mt-1 text-xs text-slate-500">
                @if ($summary['no_show_rate'] === null)
                    no decided appointments in this period
                @else
                    {{ $summary['no_show_rate'] }}% of {{ $summary['no_show_base'] }} decided &middot;
                    {{ $summary['arrivals'] }} client(s) actually checked in
                @endif
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <dt class="text-sm font-medium text-slate-600">Skipped at the counter</dt>
            <dd class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['skipped'] }}</dd>
            <p class="mt-1 text-xs text-slate-500">
                Called but not ready. These tickets can still be served, so they are not
                counted as lost appointments.
            </p>
        </div>
    </dl>

    {{-- Wait and service time, with estimate accuracy --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">Wait and service time</h2>

    <div class="mt-3 grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="text-sm font-medium text-slate-600">Average time waiting</h3>
            <p class="mt-2">
                <x-admin.reports.metric :value="$summary['avg_wait_minutes']"
                                        :samples="$summary['wait_samples']"
                                        :reliable="$summary['wait_is_reliable']"
                                        suffix=" min" :decimals="1" />
            </p>
            @if ($summary['longest_wait_minutes'] !== null)
                <p class="mt-2 text-xs text-slate-500">
                    Longest wait: {{ $summary['longest_wait_minutes'] }} minutes
                </p>
            @endif
            <p class="mt-2 text-xs text-slate-500">
                Measured from check-in to being served, for completed appointments only.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="text-sm font-medium text-slate-600">Average time at the counter</h3>
            <p class="mt-2">
                <x-admin.reports.metric :value="$summary['avg_service_minutes']"
                                        :samples="$summary['service_samples']"
                                        :reliable="$summary['service_is_reliable']"
                                        suffix=" min" :decimals="1" />
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Compare with each service's configured duration below &mdash; a service
                consistently taking longer than it says is misconfigured.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="text-sm font-medium text-slate-600">How accurate is the wait estimate?</h3>

            @if ($summary['estimate']['samples'] === 0)
                <p class="mt-2 text-sm text-slate-600">No data</p>
                <p class="mt-1 text-xs text-slate-500">
                    No completed appointment in this period has a stored estimate to compare
                    against. Estimates are recorded when a client checks in.
                </p>
            @else
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-600">Promised on average</dt>
                        <dd class="font-medium text-slate-900">{{ $summary['estimate']['average_estimate'] }} min</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-600">Actually waited</dt>
                        <dd class="font-medium text-slate-900">{{ $summary['estimate']['average_actual'] }} min</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-600">Average miss</dt>
                        <dd class="font-medium text-slate-900">&plusmn;{{ $summary['estimate']['average_error'] }} min</dd>
                    </div>
                </dl>

                <p class="mt-2 text-xs text-slate-500">
                    {{ $summary['estimate']['samples'] }} completed
                    {{ $summary['estimate']['samples'] === 1 ? 'appointment' : 'appointments' }}:
                    {{ $summary['estimate']['under_promised'] }} waited longer than promised,
                    {{ $summary['estimate']['over_promised'] }} were served sooner.
                </p>
            @endif
        </div>
    </div>

    {{-- Demand against capacity --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">Demand against capacity, by service</h2>
    <p class="mt-1 text-sm text-slate-600">
        Appointments are counted for the days they belong to, and places offered is
        the capacity of the slots on the calendar for those same days.
        &ldquo;Slots filled&rdquo; counts slots where demand reached the ceiling at
        some point &mdash; not how full they are right now, since a slot empties the
        moment a client is served.
    </p>

    <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Bookings, capacity and waits per service</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-2">Service</th>
                    <th scope="col" class="px-4 py-2">Appointments</th>
                    <th scope="col" class="px-4 py-2">Done</th>
                    <th scope="col" class="px-4 py-2">Places offered</th>
                    <th scope="col" class="px-4 py-2">Utilisation</th>
                    <th scope="col" class="px-4 py-2">Slots filled</th>
                    <th scope="col" class="px-4 py-2">Average wait</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($services as $row)
                    <tr>
                        <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                            {{ $row['service']->name }}
                            <span class="block text-xs font-normal text-slate-500">
                                {{ $row['service']->window?->name }} &middot;
                                {{ $row['service']->duration_minutes }} min nominal
                            </span>
                        </th>
                        <td class="px-4 py-3">{{ $row['booked'] }}</td>
                        <td class="px-4 py-3">{{ $row['completed'] }}</td>
                        <td class="px-4 py-3">
                            {{ $row['capacity'] }}
                            @if ($row['slot_count'] > 0)
                                <span class="block text-xs text-slate-500">
                                    across {{ $row['slot_count'] }} slot{{ $row['slot_count'] === 1 ? '' : 's' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($row['utilisation'] === null)
                                <span class="text-slate-600" title="No slots were on offer, so there is nothing to measure against.">
                                    No slots offered
                                </span>
                            @else
                                <span class="font-medium">{{ $row['utilisation'] }}%</span>
                                @if ($row['turn_away_risk'])
                                    <span class="mt-1 block rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700">
                                        Turn-away risk
                                    </span>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $row['filled_slots'] }}
                            @if ($row['filled_slots'] > 0)
                                <span class="block text-xs text-slate-500">reached capacity</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <x-admin.reports.metric :value="$row['avg_wait']"
                                                    :samples="$row['wait_samples']"
                                                    :reliable="$row['wait_is_reliable']"
                                                    suffix=" min" :decimals="1" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                            No services configured yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Per window --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">Service windows</h2>

    <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Performance per service window</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-2">Window</th>
                    <th scope="col" class="px-4 py-2">Booked</th>
                    <th scope="col" class="px-4 py-2">Completed</th>
                    <th scope="col" class="px-4 py-2">No show</th>
                    <th scope="col" class="px-4 py-2">Average wait</th>
                    <th scope="col" class="px-4 py-2">Fees recorded</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($windows as $row)
                    <tr>
                        <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                            {{ $row['window']->name }}
                        </th>
                        <td class="px-4 py-3">{{ $row['booked'] }}</td>
                        <td class="px-4 py-3">
                            {{ $row['completed'] }}
                            @if ($row['completion_rate'] !== null)
                                <span class="block text-xs text-slate-500">{{ $row['completion_rate'] }}%</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $row['no_show'] }}
                            @if ($row['no_show_rate'] !== null)
                                <span class="block text-xs text-slate-500">{{ $row['no_show_rate'] }}%</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <x-admin.reports.metric :value="$row['avg_wait']"
                                                    :samples="$row['wait_samples']"
                                                    :reliable="$row['wait_is_reliable']"
                                                    suffix=" min" :decimals="1" />
                        </td>
                        <td class="px-4 py-3">&#8369;{{ number_format($row['fees_collected'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                            No service windows configured yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- "Recorded", not "collected", because nothing here took a payment.

             The figure is transactions.fee_paid: an amount staff typed in when
             they closed a transaction. Calling it "Fees collected" claimed the
             system had handled money, which it has not and does not - and the
             column was renamed on the PDF at the same time so the two documents
             cannot disagree about what the number is.
        --}}
        <p class="mt-2 text-xs text-slate-600">
            <strong>Fees recorded</strong> is the amount staff entered when they closed a
            transaction. GovQueue does not take payment and does not know what clients actually
            paid, so treat this as a tally of what was written down, not as money received.
        </p>
    </div>

    {{-- When people actually turn up --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">When clients arrive</h2>
    <p class="mt-1 text-sm text-slate-600">
        Check-ins by hour, so staffing can be matched to demand. Empty hours are
        shown as zero on purpose &mdash; a quiet afternoon is a finding.
    </p>

    @php
        $busiest = collect($peakHours)->max('arrivals');
        $officeHours = array_slice($peakHours, 6, 14); // 6am to 8pm
    @endphp

    @if (empty($officeHours))
        <div class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
            No arrivals recorded in this period.
        </div>
    @else
    <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white p-4">
        <table class="min-w-full text-xs">
            <caption class="sr-only">Client arrivals and completions by hour of day</caption>
            <thead class="text-left text-slate-500">
                <tr>
                    <th scope="col" class="px-2 py-1">Hour</th>
                    @foreach ($officeHours as $bucket)
                        <th scope="col" class="px-1 py-1 text-center font-normal">{{ $bucket['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row" class="py-1 pr-2 text-left font-medium text-slate-600">Arrived</th>
                    @foreach ($officeHours as $bucket)
                        @php
                            // Height and shade both scale with the busiest hour, so
                            // the shape reads even without colour.
                            $height = $busiest > 0 ? max(3, round($bucket['arrivals'] / $busiest * 56)) : 3;
                            $shade = $busiest > 0
                                ? round(255 - ($bucket['arrivals'] / $busiest) * 150)
                                : 245;
                        @endphp
                        <td class="px-1 py-1 text-center align-bottom">
                            <div class="mx-auto rounded-sm"
                                 style="height: {{ $height }}px; background-color: rgb({{ $shade }}, {{ $shade }}, 253);"
                                 title="{{ $bucket['arrivals'] }} arrived at {{ $bucket['label'] }}"></div>
                            <span class="mt-1 block tabular-nums text-slate-600">{{ $bucket['arrivals'] }}</span>
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <th scope="row" class="py-1 pr-2 text-left font-medium text-slate-600">Waited</th>
                    @foreach ($officeHours as $bucket)
                        <td class="px-1 py-1 text-center tabular-nums text-slate-600">
                            @if ($bucket['wait_average'] === null)
                                <span class="text-slate-500">&mdash;</span>
                            @else
                                {{ $bucket['wait_average'] }}m
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>

        <p class="mt-3 text-xs text-slate-500">
            Wait figures are based on fewer than
            {{ \App\Services\ReportingService::RELIABLE_SAMPLE }} appointments for that hour and are
            not reliable. Nights outside 6am&ndash;8pm are omitted from the chart but included in totals.
        </p>
    </div>
    @endif

    {{-- Trend --}}
    @php $trendMax = collect($trend)->max(fn ($d) => max($d['booked'], $d['completed'])); @endphp

    @if (count($trend) > 1)
        <h2 class="mt-8 text-lg font-bold text-slate-900">Daily trend</h2>

        <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white p-4">
            <table class="min-w-full text-xs">
                <caption class="sr-only">Appointments booked and completed per day</caption>
                <thead class="text-left text-slate-500">
                    <tr>
                        <th scope="col" class="px-2 py-1">Day</th>
                        @foreach ($trend as $day)
                            <th scope="col" class="px-1 py-1 text-center font-normal">{{ $day['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row" class="py-1 pr-2 text-left font-medium text-blue-700">Booked</th>
                        @foreach ($trend as $day)
                            @php
                                $height = $trendMax > 0 ? max(3, round($day['booked'] / $trendMax * 52)) : 3;
                                $shade = $trendMax > 0 ? round(255 - ($day['booked'] / $trendMax) * 130) : 245;
                            @endphp
                            <td class="px-1 py-1 text-center align-bottom">
                                <div class="mx-auto rounded-sm"
                                     style="height: {{ $height }}px; background-color: rgb({{ $shade }}, {{ $shade }}, 253);"
                                     title="{{ $day['booked'] }} booked on {{ $day['label'] }}"></div>
                                <span class="mt-1 block tabular-nums text-slate-600">{{ $day['booked'] }}</span>
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        <th scope="row" class="py-1 pr-2 text-left font-medium text-emerald-700">Completed</th>
                        @foreach ($trend as $day)
                            @php
                                $height = $trendMax > 0 ? max(3, round($day['completed'] / $trendMax * 52)) : 3;
                                $shade = $trendMax > 0 ? round(255 - ($day['completed'] / $trendMax) * 130) : 245;
                            @endphp
                            <td class="px-1 py-1 text-center align-bottom">
                                <div class="mx-auto rounded-sm"
                                     style="height: {{ $height }}px; background-color: rgb(236, {{ $shade }}, {{ $shade }});"
                                     title="{{ $day['completed'] }} completed on {{ $day['label'] }}"></div>
                                <span class="mt-1 block tabular-nums text-slate-600">{{ $day['completed'] }}</span>
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    {{-- Statutory priority --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">Priority claims (RA 9994 / RA 10754)</h2>
    <p class="mt-1 max-w-3xl text-sm text-slate-600">
        What clients claimed against what staff decided at the counter. A claim only
        grants precedence once a staff member has verified it, so unverified claims
        are counted separately from approved ones.
    </p>

    <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Priority claims approved and refused by category</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-2">Category</th>
                    <th scope="col" class="px-4 py-2">Claimed</th>
                    <th scope="col" class="px-4 py-2">Approved</th>
                    <th scope="col" class="px-4 py-2">Refused</th>
                    <th scope="col" class="px-4 py-2">Awaiting decision</th>
                    <th scope="col" class="px-4 py-2">Approval rate</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($priority['categories'] as $row)
                    <tr>
                        <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                            {{ $row['label'] }}
                            <span class="block text-xs font-normal text-slate-500">{{ $row['basis'] }}</span>
                        </th>
                        <td class="px-4 py-3">{{ $row['claimed'] }}</td>
                        <td class="px-4 py-3">{{ $row['approved'] }}</td>
                        <td class="px-4 py-3">{{ $row['refused'] }}</td>
                        <td class="px-4 py-3">{{ $row['awaiting_decision'] }}</td>
                        <td class="px-4 py-3">
                            @if ($row['approval_rate'] === null)
                                <span class="text-slate-600">no decisions yet</span>
                            @else
                                {{ $row['approval_rate'] }}%
                                <span class="block text-xs text-slate-500">of {{ $row['decided'] }} decided</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">
                            No priority claims recorded in this period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($priority['refusal_reasons'] !== [])
        <div class="mt-3 rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-800">Reasons staff gave for refusing a claim</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($priority['refusal_reasons'] as $reason => $count)
                    <li class="flex justify-between gap-3">
                        <span class="text-slate-700">{{ $reason }}</span>
                        <span class="tabular-nums text-slate-500">{{ $count }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Why people cancelled --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">Why appointments were cancelled</h2>

    @if ($cancellationReasons->isEmpty())
        <p class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500">
            No cancellations with a recorded reason in this period.
        </p>
    @else
        <ul class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
            @foreach ($cancellationReasons as $row)
                <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <span class="text-slate-700">{{ $row['reason'] }}</span>
                    <span class="tabular-nums font-medium text-slate-900">{{ $row['total'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
