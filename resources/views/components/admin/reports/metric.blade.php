{{--
    Formatting helpers for the reports screen.

    Kept as a Blade component so the "no data" wording is written once. Showing 0
    for an average with nothing behind it would read as "we served everyone
    instantly", which is the opposite of the truth.
--}}
@props([
    'value' => null,
    'samples' => 0,
    'reliable' => false,
    'suffix' => '',
    'decimals' => 0,
])

@php
    $hasData = $value !== null;
    $thin = $hasData && $samples > 0 && $samples < \App\Services\ReportingService::RELIABLE_SAMPLE;
@endphp

<span class="inline-flex flex-wrap items-baseline gap-x-1.5">
    @if ($hasData)
        <span class="font-semibold text-slate-900">{{ number_format((float) $value, $decimals) }}{{ $suffix }}</span>

        <span class="text-xs text-slate-500">
            from {{ $samples }} {{ $samples === 1 ? 'sample' : 'samples' }}
        </span>

        @if ($thin)
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                  title="Too few observations to treat this as a reliable figure.">
                indicative only
            </span>
        @endif
    @else
        <span class="font-semibold text-slate-600">No data</span>
        <span class="text-xs text-slate-500">nothing to measure in this period</span>
    @endif
</span>
