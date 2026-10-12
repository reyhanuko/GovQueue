{{--
    The printable report.

    Self-contained on purpose. DomPDF cannot fetch a stylesheet, a web font or an
    image over the network reliably - each one either fails silently or adds
    seconds per page - so this template carries its own CSS and uses the standard
    PDF fonts. That also means the PDF renders identically on a machine with no
    internet, which is the likely condition of the office printing it.

    Written for paper, not for a screen: a header identifying the office and the
    exact period, every figure labelled so a page pulled out of a file is still
    readable on its own, and a "what this is not" footnote - because a percentage
    with no base beside it is the easiest thing in a report to be misread.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $office }} &mdash; GovQueue report</title>
    <style>
        /* The PDF renderer understands a subset of CSS 2.1. No grid, no flexbox
           in the places that matter for layout, no custom properties - all of
           those are silently ignored and produce a broken page. Tables and
           simple block layout are what actually works. */
        @page {
            margin: 28px 30px 40px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #1e293b;
            line-height: 1.45;
        }

        h1 { font-size: 16pt; margin: 0 0 2px; }
        h2 { font-size: 11pt; margin: 18px 0 6px; }
        h3 { font-size: 10pt; margin: 0 0 4px; }

        p { margin: 0 0 6px; }

        .meta { color: #64748b; font-size: 9pt; }

        .rule { border-bottom: 2px solid #1d4ed8; margin-bottom: 12px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; vertical-align: top; }
        thead th { background: #f1f5f9; font-size: 8.5pt; text-transform: uppercase; }
        td.num, th.num { text-align: right; }

        /* Headline figures. A fixed-width table so the boxes sit side by side,
           which is the one layout grid-free CSS still does reliably. */
        .tiles { width: 100%; border-collapse: separate; border-spacing: 6px 0; }
        .tiles td { border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 8px; }
        .tiles .figure { font-size: 14pt; font-weight: bold; display: block; }
        .tiles .label { font-size: 8.5pt; color: #475569; }

        .foot { margin-top: 16px; font-size: 8.5pt; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 6px; }

        .none { color: #94a3b8; font-style: italic; }
    </style>
</head>
<body>
    <h1>{{ $office }}</h1>
    <p class="meta">GovQueue service performance report</p>
    <div class="rule"></div>

    <p>
        <strong>Period:</strong>
        {{ $from->format('j F Y') }} to {{ $to->format('j F Y') }}
        ({{ $days }} {{ $days === 1 ? 'day' : 'days' }})
    </p>
    <p class="meta">
        Generated {{ $generatedAt->format('j F Y, g:i A') }}.
        Every figure below comes from the same query as the on-screen report for this period.
    </p>

    {{-- At a glance --}}
    <h2>At a glance</h2>
    <table class="tiles">
        <tr>
            <td>
                <span class="figure">{{ $summary['in_period'] }}</span>
                <span class="label">Appointments in period</span>
            </td>
            <td>
                <span class="figure">{{ $summary['bookings_made'] }}</span>
                <span class="label">Bookings made</span>
            </td>
            <td>
                <span class="figure">{{ $summary['completed'] }}</span>
                <span class="label">Completed</span>
            </td>
            <td>
                <span class="figure">{{ $summary['cancelled'] }}</span>
                <span class="label">Cancelled</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="figure">{{ $summary['no_show'] }}</span>
                <span class="label">Did not show up</span>
            </td>
            <td>
                <span class="figure">{{ $summary['skipped'] }}</span>
                <span class="label">Skipped at the counter</span>
            </td>
            <td>
                <span class="figure">
                    {{ $summary['cancellation_rate'] === null ? 'n/a' : $summary['cancellation_rate'].'%' }}
                </span>
                <span class="label">
                    Cancellation rate
                    @if ($summary['cancellation_rate'] !== null)
                        (of {{ $summary['cancellation_base'] }} decided)
                    @endif
                </span>
            </td>
            <td>
                <span class="figure">
                    {{ $summary['no_show_rate'] === null ? 'n/a' : $summary['no_show_rate'].'%' }}
                </span>
                <span class="label">
                    No-show rate
                    @if ($summary['no_show_rate'] !== null)
                        (of {{ $summary['no_show_base'] }} decided)
                    @endif
                </span>
            </td>
        </tr>
    </table>

    <p class="meta">
        A rate is omitted rather than shown as zero when there are no decided
        appointments in the period to divide by. Skipped tickets are counted
        separately because they can still be served, so they are not lost
        appointments.
    </p>

    {{-- Waits --}}
    <h2>Wait and service time</h2>
    <table>
        <thead>
            <tr>
                <th>Measure</th>
                <th class="num">Average</th>
                <th class="num">Samples</th>
                <th>Basis</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Time waiting</td>
                <td class="num">
                    @if ($summary['avg_wait_minutes'] === null)
                        <span class="none">no data</span>
                    @else
                        {{ number_format($summary['avg_wait_minutes'], 1) }} min
                    @endif
                </td>
                <td class="num">{{ $summary['wait_samples'] }}</td>
                <td>Check-in to being served, completed appointments only.</td>
            </tr>
            <tr>
                <td>Longest single wait</td>
                <td class="num">
                    @if ($summary['longest_wait_minutes'] === null)
                        <span class="none">no data</span>
                    @else
                        {{ $summary['longest_wait_minutes'] }} min
                    @endif
                </td>
                <td class="num">&mdash;</td>
                <td>&nbsp;</td>
            </tr>
            <tr>
                <td>Time at the counter</td>
                <td class="num">
                    @if ($summary['avg_service_minutes'] === null)
                        <span class="none">no data</span>
                    @else
                        {{ number_format($summary['avg_service_minutes'], 1) }} min
                    @endif
                </td>
                <td class="num">{{ $summary['service_samples'] }}</td>
                <td>Being served to completion.</td>
            </tr>
        </tbody>
    </table>

    @if ($summary['estimate']['samples'] > 0)
        <h3>How accurate was the wait estimate?</h3>
        <table>
            <thead>
                <tr>
                    <th>Promised on average</th>
                    <th>Actually waited</th>
                    <th>Average miss</th>
                    <th class="num">Samples</th>
                    <th class="num">Waited longer</th>
                    <th class="num">Served sooner</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $summary['estimate']['average_estimate'] }} min</td>
                    <td>{{ $summary['estimate']['average_actual'] }} min</td>
                    <td>&plusmn;{{ $summary['estimate']['average_error'] }} min</td>
                    <td class="num">{{ $summary['estimate']['samples'] }}</td>
                    <td class="num">{{ $summary['estimate']['under_promised'] }}</td>
                    <td class="num">{{ $summary['estimate']['over_promised'] }}</td>
                </tr>
            </tbody>
        </table>
    @else
        <p class="none">
            No completed appointment in this period has a stored estimate to compare
            against, so estimate accuracy cannot be reported. Estimates are recorded
            when a client checks in.
        </p>
    @endif

    {{-- Per service --}}
    <h2>Demand against capacity, by service</h2>
    @if ($services->isEmpty())
        <p class="none">No services had any activity in this period.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Window</th>
                    <th class="num">Appointments</th>
                    <th class="num">Done</th>
                    <th class="num">Places offered</th>
                    <th class="num">Utilisation</th>
                    <th class="num">Slots filled</th>
                    <th class="num">Avg wait</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($services as $row)
                    <tr>
                        <td>{{ $row['service']->name }}</td>
                        <td>{{ $row['service']->window?->name ?? '—' }}</td>
                        <td class="num">{{ $row['booked'] }}</td>
                        <td class="num">{{ $row['completed'] }}</td>
                        <td class="num">{{ $row['capacity'] }}</td>
                        <td class="num">
                            {{ $row['utilisation'] === null ? 'n/a' : $row['utilisation'].'%' }}
                        </td>
                        <td class="num">{{ $row['filled_slots'] }}</td>
                        <td class="num">
                            {{ $row['avg_wait'] === null ? 'no data' : $row['avg_wait'].' min' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="meta">
            Utilisation is omitted rather than shown as zero where no places were
            offered, because zero would be indistinguishable from a service that
            offered capacity and nobody used. &ldquo;Slots filled&rdquo; counts slots
            where demand reached the ceiling at some point, not how full they are now.
        </p>
    @endif

    {{-- Per window --}}
    <h2>By window</h2>
    @if ($windows->isEmpty())
        <p class="none">No window had any activity in this period.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Window</th>
                    <th class="num">Appointments</th>
                    <th class="num">Completed</th>
                    <th class="num">Cancelled</th>
                    <th class="num">No show</th>
                    <th class="num">Avg wait</th>
                    <th class="num">Fees collected</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($windows as $row)
                    <tr>
                        <td>{{ $row['window']->name }}</td>
                        <td class="num">{{ $row['booked'] }}</td>
                        <td class="num">{{ $row['completed'] }}</td>
                        <td class="num">{{ $row['cancelled'] }}</td>
                        <td class="num">{{ $row['no_show'] }}</td>
                        <td class="num">
                            {{ $row['avg_wait'] === null ? 'no data' : $row['avg_wait'].' min' }}
                        </td>
                        <td class="num">&#8369;{{ number_format($row['fees_collected'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Priority --}}
    <h2>Statutory priority handling</h2>
    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th>Legal basis</th>
                <th class="num">Claimed</th>
                <th class="num">Verified by staff</th>
                <th class="num">Refused</th>
                <th class="num">Awaiting decision</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($priority['categories'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['basis'] }}</td>
                    <td class="num">{{ $row['claimed'] }}</td>
                    <td class="num">{{ $row['approved'] }}</td>
                    <td class="num">{{ $row['refused'] }}</td>
                    <td class="num">{{ $row['awaiting_decision'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="none">No priority claims were recorded in this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @php
        /*
         * priorityHandling() always returns all three categories, so an
         * @empty here would be dead code and a reader would see a table of
         * zeroes with nothing to explain it. The note is the honest version:
         * the categories are shown, and it says plainly that nobody claimed
         * anything, so the zeroes are not mistaken for missed data.
         */
        $priorityTotal = collect($priority['categories'])->sum(fn ($row) => (int) $row['claimed']);
    @endphp

    <p class="meta">
        @if ($priorityTotal === 0)
            No client claimed a priority category in this period; the zeroes above are
            genuine, not missing data.
        @else
            &ldquo;Awaiting decision&rdquo; is a claim still standing on a ticket that staff have
            not yet verified or refused. It is not an error: a client whose turn has not come
            has not been asked yet.
        @endif
    </p>

    {{--
        Busiest hours. All 24 are listed, including the empty ones, for the
        same reason the on-screen chart keeps them: a quiet hour is a finding.
        Six hours per table row so the numbers stay legible on A4.
    --}}
    <h2>Arrivals by hour</h2>
    <table>
        <thead>
            <tr>
                <th>Hour</th>
                <th class="num">Arrivals</th>
                <th class="num">Completed</th>
                <th class="num">Avg wait</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_chunk($peakHours, 6) as $chunk)
                <tr>
                    @foreach ($chunk as $row)
                        <td>
                            {{ $row['label'] }}<br />
                            <span class="meta">
                                {{ $row['arrivals'] }} in &middot;
                                {{ $row['completed'] }} done
                                @if ($row['wait_average'] !== null)
                                    &middot; {{ $row['wait_average'] }} min wait
                                @endif
                            </span>
                        </td>
                    @endforeach
                    {{-- Pad the final row so the table does not end ragged. --}}
                    @for ($i = count($chunk); $i < 6; $i++)
                        <td>&nbsp;</td>
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="foot">
        GovQueue does not verify statutory eligibility itself. Priority is applied only
        after a member of staff has confirmed a client's claim at the counter, so the
        figures above are a record of what staff verified &mdash; not an independent
        determination that any individual met RA 9994 or RA 10754.
    </p>
</body>
</html>
