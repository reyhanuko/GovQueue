@extends('layouts.app')

@section('title', 'Appointment '.$appointment->reference_number)

@section('content')
    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('client.appointments.index') }}" class="text-blue-700 hover:underline">My appointments</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">{{ $appointment->reference_number }}</span>
    </nav>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $appointment->service->name }}</h1>
            <p class="mt-1 font-mono text-sm text-slate-500">{{ $appointment->reference_number }}</p>
        </div>
        <span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold {{ $appointment->status->badgeClasses() }}">
            {{ $appointment->status->label() }}
        </span>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <h2 class="font-bold text-slate-900">Your appointment</h2>

                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Date</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $appointment->scheduledDateLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Time</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $appointment->scheduledTimeLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Window</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $appointment->window->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Booking type</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $appointment->source->label() }}</dd>
                    </div>
                </dl>

                @if ($appointment->client_note)
                    <div class="mt-4 rounded-lg bg-slate-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your note to staff</p>
                        <p class="mt-1 text-sm text-slate-700">{{ $appointment->client_note }}</p>
                    </div>
                @endif
            </div>

            @if ($appointment->claimedPriorities())
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                    <h2 class="font-bold text-amber-900">Priority claim</h2>
                    <p class="mt-1 text-sm text-amber-800">
                        You claimed: <strong>{{ $appointment->priorityLabels() }}</strong>.
                        @if ($appointment->priority_reference)
                            <br>Reference on file: <span class="font-mono text-xs">{{ $appointment->priority_reference }}</span>
                        @endif
                    </p>

                    @if ($appointment->hasVerifiedPriority())
                        <p class="mt-3 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            <strong>Verified.</strong> Staff confirmed this claim on
                            {{ $appointment->priority_verified_at->format('M d, Y g:i A') }}. You will be
                            reordered ahead of ordinary clients in the queue.
                        </p>
                    @else
                        <p class="mt-3 rounded-lg border border-amber-300 bg-white px-4 py-3 text-sm text-amber-800">
                            <strong>Not yet verified.</strong> Your claim takes effect only after staff
                            confirm it at the counter. Bring your supporting documents.
                        </p>
                    @endif
                </div>
            @endif

            @if ($appointment->statusHistories->isNotEmpty())
                <div class="rounded-xl border border-slate-200 bg-white p-6">
                    <h2 class="font-bold text-slate-900">Activity</h2>
                    <ol class="mt-4 space-y-4">
                        @foreach ($appointment->statusHistories->reverse() as $entry)
                            <li class="flex gap-3">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-blue-700" aria-hidden="true"></span>
                                <div>
                                    <p class="text-sm font-medium text-slate-800">
                                        {{ \App\Enums\AppointmentStatus::from($entry->to_status)->label() }}
                                    </p>
                                    @if ($entry->note)
                                        <p class="text-sm text-slate-600">{{ $entry->note }}</p>
                                    @endif
                                    <p class="mt-0.5 text-xs text-slate-400">
                                        {{ $entry->created_at->format('M d, Y g:i A') }}
                                        @if ($entry->actor_name) &middot; {{ $entry->actor_name }} @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
        </div>

        <aside class="space-y-4">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 text-center">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Your queue number</p>
                <p class="mt-2 text-4xl font-black text-blue-800">{{ $appointment->queue_number ?? 'Assigned on arrival' }}</p>
                <p class="mt-2 text-xs text-blue-700">
                    Numbered for {{ $appointment->scheduledDateLabel() }} at {{ $appointment->window->name }}
                </p>
            </div>

            @if ($appointment->status->isActive())
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="font-bold text-slate-900">Manage this appointment</h2>

                    @if (in_array($appointment->status->value, ['pending', 'confirmed'], true))
                        <a href="{{ route('client.appointments.reschedule', $appointment) }}"
                           class="mt-3 block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Move to another slot
                        </a>
                    @endif

                    <form method="POST" action="{{ route('client.appointments.cancel', $appointment) }}"
                          class="mt-2"
                          x-data="{ open: false }">
                        @csrf
                        @method('DELETE')

                        <template x-if="! open">
                            <button type="button" @click="open = true"
                                    class="w-full rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                Cancel appointment
                            </button>
                        </template>

                        <div x-show="open" x-cloak>
                            <label for="reason" class="block text-sm font-medium text-slate-700">
                                Reason <span class="font-normal text-slate-500">(optional)</span>
                            </label>
                            <input id="reason" name="reason" type="text"
                                   placeholder="e.g. Schedule conflict"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500">

                            <div class="mt-3 flex gap-2">
                                <button type="submit"
                                        class="flex-1 rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                                    Yes, cancel it
                                </button>
                                <button type="button" @click="open = false"
                                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    Keep
                                </button>
                            </div>

                            <p class="mt-2 text-xs text-slate-500">
                                Your place is released to another client immediately.
                            </p>
                        </div>
                    </form>
                </div>
            @else
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="font-bold text-slate-900">This appointment is closed</h2>
                    @if ($appointment->cancel_reason)
                        <p class="mt-2 text-sm text-slate-600">Reason: {{ $appointment->cancel_reason }}</p>
                    @endif
                    <a href="{{ route('services.index') }}"
                       class="mt-3 block rounded-lg bg-blue-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-blue-800">
                        Book again
                    </a>
                </div>
            @endif

            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-bold text-slate-900">On the day</h2>
                <ul class="mt-2 space-y-2 text-sm text-slate-600">
                    <li>Come to {{ $appointment->window->name }} during your booked time.</li>
                    <li>Check in at the counter so staff can verify your details.</li>
                    <li>Your queue number is called on the public display.</li>
                    @if ($appointment->claimedPriorities() && ! $appointment->hasVerifiedPriority())
                        <li>Bring your supporting document so staff can verify your priority claim.</li>
                    @endif
                </ul>
            </div>
        </aside>
    </div>
@endsection