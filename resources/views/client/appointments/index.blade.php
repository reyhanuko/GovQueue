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
            <a href="{{ route('services.index') }}" class="mt-3 inline-block text-sm font-semibold text-blue-700 hover:underline">
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

    <h2 class="mt-10 text-lg font-bold text-slate-900">History</h2>

    @if ($past->isEmpty())
        <p class="mt-3 text-sm text-slate-600">No completed or cancelled transactions yet.</p>
    @else
        <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Reference</th>
                        <th scope="col" class="px-4 py-3">Service</th>
                        <th scope="col" class="px-4 py-3">Date</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3 sr-only">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($past as $appointment)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono text-xs">{{ $appointment->reference_number }}</td>
                            <td class="px-4 py-3">{{ $appointment->service->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $appointment->scheduledDateLabel() }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $appointment->status->badgeClasses() }}">
                                    {{ $appointment->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('client.appointments.show', $appointment) }}"
                                   class="text-xs font-semibold text-blue-700 hover:underline">
                                    View
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection