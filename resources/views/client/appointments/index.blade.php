@extends('layouts.app')

@section('title', 'My Appointments')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">My appointments</h1>
            <p class="mt-1 text-sm text-slate-600">Your bookings, queue numbers, and current status.</p>
        </div>
        <a href="{{ route('services.index') }}"
           class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Book an appointment
        </a>
    </div>

    <h2 class="mt-8 text-lg font-bold text-slate-900">Upcoming &amp; active</h2>

    @if ($upcoming->isEmpty())
        <div class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            <p class="text-sm text-slate-600">You have no active appointments.</p>
            {{-- py-1 so the link is a target of its own rather than a line of
                 text: at text-sm it is 20 pixels tall, and a finger on a phone
                 is not a mouse cursor. --}}
            <a href="{{ route('services.index') }}" class="mt-3 inline-block py-1 text-sm font-semibold text-blue-700 hover:underline">
                Browse available services
            </a>
        </div>
    @else
        <ul class="mt-3 space-y-3">
            @foreach ($upcoming as $appointment)
                <li class="group rounded-xl border border-slate-200 bg-white p-5 transition hover:border-blue-200 hover:shadow-sm">
                    <a href="{{ route('client.appointments.show', $appointment) }}"
                       class="block hover:bg-slate-50 rounded-lg -m-1 p-1 transition-colors">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-bold text-slate-900">{{ $appointment->service->name }}</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $appointment->scheduledDateLabel() }} &middot; {{ $appointment->scheduledTimeLabel() }}
                                    @if ($appointment->window) &middot; {{ $appointment->window->name }} @endif
                                </p>
                                <p class="mt-1 font-mono text-xs text-slate-500">
                                    Ref {{ $appointment->reference_number }}
                                </p>
                            </div>

                            <div class="text-right">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $appointment->status->badgeClasses() }}">
                                    {{ $appointment->status->label() }}
                                </span>
                                @if ($appointment->queue_number)
                                    <p class="mt-2 text-xs text-slate-500">Queue number</p>
                                    <p class="text-2xl font-black text-blue-700">{{ $appointment->queue_number }}</p>
                                @endif
                                <p class="mt-2 text-xs font-semibold text-blue-700 group-hover:underline">
                                    View details →
                                </p>
                            </div>
                        </div>
                    </a>

                    @if ($appointment->claims_senior_citizen || $appointment->claims_pwd || $appointment->claims_pregnant)
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Priority claimed:
                            <strong>{{ $appointment->priorityLabels() }}</strong>.
                            @if (! $appointment->hasVerifiedPriority())
                                <span>Not yet verified &mdash; precedence applies after staff confirm it at the counter.</span>
                            @else
                                <span>Verified on {{ $appointment->priority_verified_at->format('M d, g:i A') }}.</span>
                            @endif
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-10 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-bold text-slate-900">History</h2>

        {{-- Plain links, no JavaScript. A filter that needs a script to work is
             a filter that does not work on the slow connection this page is
             likely being read on. --}}
        <nav aria-label="Filter history by status" class="flex flex-wrap gap-2">
            <a href="{{ route('client.appointments.index') }}"
               class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusFilter === '' ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-blue-300' }}">
                All
                <span class="sr-only">history</span>
            </a>
            @foreach ($historyFilters as $status)
                <a href="{{ route('client.appointments.index', ['status' => $status]) }}"
                   class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusFilter === $status ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-blue-300' }}">
                    {{ \App\Enums\AppointmentStatus::from($status)->label() }}
                </a>
            @endforeach
        </nav>
    </div>

    @if ($history->isEmpty())
        <div class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            @if ($statusFilter !== '')
                <p class="text-sm text-slate-600">
                    No {{ strtolower(\App\Enums\AppointmentStatus::from($statusFilter)->label()) }} appointments.
                </p>
                <a href="{{ route('client.appointments.index') }}"
                   class="mt-2 inline-block text-sm font-semibold text-blue-700 hover:underline">
                    Show all history
                </a>
            @else
                <p class="text-sm text-slate-600">Nothing in your history yet.</p>
                <p class="mt-1 text-xs text-slate-500">
                    Appointments appear here once they have been completed, cancelled, or missed.
                </p>
            @endif
        </div>
    @else
        <ul class="mt-3 space-y-2">
            @foreach ($history as $appointment)
                <li>
                    <a href="{{ route('client.appointments.show', $appointment) }}"
                       class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 transition hover:border-blue-200 hover:bg-slate-50">
                        <span class="min-w-0">
                            <span class="block font-medium text-slate-900">{{ $appointment->service->name }}</span>
                            <span class="mt-0.5 block text-sm text-slate-600">
                                {{ $appointment->scheduledDateLabel() }}
                                <span class="font-mono text-xs text-slate-400">{{ $appointment->reference_number }}</span>
                            </span>
                        </span>
                        <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $appointment->status->badgeClasses() }}">
                            {{ $appointment->status->label() }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($history->hasPages())
            <nav aria-label="History pages" class="mt-4 flex items-center justify-between gap-3">
                @if ($history->onFirstPage())
                    <span class="text-sm text-slate-400">Newest first</span>
                @else
                    <a href="{{ $history->previousPageUrl() }}"
                       class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:bg-slate-50">
                        &larr; Newer
                    </a>
                @endif

                <span class="text-sm text-slate-500">
                    Showing {{ $history->firstItem() }}&ndash;{{ $history->lastItem() }}
                    of {{ $history->total() }}
                </span>

                @if ($history->hasMorePages())
                    <a href="{{ $history->nextPageUrl() }}"
                       class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:bg-slate-50">
                        Older &rarr;
                    </a>
                @else
                    <span class="text-sm text-slate-400">&nbsp;</span>
                @endif
            </nav>
        @endif
    @endif
@endsection