@extends('layouts.app')

@section('title', 'Services')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Frontline services</h1>
            <p class="mt-1 text-sm text-slate-600">
                {{ config('govqueue.office.lgu') }} &mdash; choose a service to see open time slots.
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('services.index') }}" class="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div class="min-w-56 flex-1">
            <label for="q" class="block text-sm font-medium text-slate-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}"
                   placeholder="e.g. clearance, permit, residency"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
        </div>

        <div class="min-w-48">
            <label for="window" class="block text-sm font-medium text-slate-700">Window</label>
            <select id="window" name="window"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">All windows</option>
                @forelse ($windows as $window)
                    <option value="{{ $window->id }}" @selected(($filters['window'] ?? '') == $window->id)>
                        {{ $window->name }}
                    </option>
                @empty
                    <option value="" disabled>No windows configured</option>
                @endforelse
            </select>
        </div>

        <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Filter
        </button>

        @if (array_filter($filters))
            <a href="{{ route('services.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
                Clear
            </a>
        @endif
    </form>

    <p class="mt-6 text-sm text-slate-600" role="status">
        {{ $services->count() }} {{ Str::plural('service', $services->count()) }} available.
    </p>

    @if ($services->isEmpty())
        <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="text-sm text-slate-600">No services match your search.</p>
            <a href="{{ route('services.index') }}" class="mt-3 inline-block text-sm font-semibold text-blue-700 hover:underline">
                Show all services
            </a>
        </div>
    @else
        <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($services as $service)
                <li class="flex flex-col rounded-xl border border-slate-200 bg-white p-5">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-bold text-slate-900">{{ $service->name }}</h2>
                        @if ($service->fee > 0)
                            <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                &#8369;{{ number_format((float) $service->fee, 2) }}
                            </span>
                        @else
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                                Free
                            </span>
                        @endif
                    </div>

                    <p class="mt-1 font-mono text-xs text-slate-400">{{ $service->code }}</p>

                    <p class="mt-2 flex-1 text-sm leading-relaxed text-slate-600">{{ $service->description }}</p>

                    <dl class="mt-4 space-y-1 text-xs text-slate-500">
                        <div class="flex justify-between">
                            <dt>Window</dt>
                            <dd class="font-medium text-slate-700">{{ $service->window->name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Typical time</dt>
                            <dd class="font-medium text-slate-700">{{ $service->duration_minutes }} min</dd>
                        </div>
                    </dl>

                    <div class="mt-4 border-t border-slate-100 pt-3">
                        @if ($service->open_slots > 0)
                            <p class="text-xs font-medium text-emerald-700">
                                {{ $service->open_slots }} open {{ Str::plural('slot', $service->open_slots) }} available
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Next: {{ $service->next_slot->start_at->format('D, M d g:i A') }}
                            </p>
                        @else
                            <p class="text-xs font-medium text-amber-700">
                                Fully booked for now
                            </p>
                        @endif

                        <a href="{{ route('services.show', $service) }}"
                           class="mt-3 inline-block rounded-lg border border-blue-700 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                            {{ $service->open_slots > 0 ? 'View time slots' : 'See schedule' }}
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection