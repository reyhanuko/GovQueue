@extends('layouts.app')

@section('title', 'Move Appointment')

@section('content')
    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('client.appointments.index') }}" class="text-blue-700 hover:underline">My appointments</a>
        <span class="mx-1 text-slate-400">/</span>
        <a href="{{ route('client.appointments.show', $appointment) }}" class="text-blue-700 hover:underline">
            {{ $appointment->reference_number }}
        </a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">Choose a new slot</span>
    </nav>

    <div class="mt-4">
        <h1 class="text-2xl font-bold text-slate-900">Move your {{ $service->name }} appointment</h1>
        <p class="mt-1 text-sm text-slate-600">
            Currently booked for
            <strong>{{ $appointment->scheduledDateLabel() }} &middot; {{ $appointment->scheduledTimeLabel() }}</strong>
            (queue number {{ $appointment->queue_number }}).
        </p>
    </div>

    @if ($availability->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="text-sm font-medium text-slate-700">No other open slots available.</p>
            <p class="mt-1 text-sm text-slate-500">
                Keep your current booking, or cancel it if you cannot make the original time.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('client.appointments.reschedule.store', $appointment) }}"
              class="mt-6 grid gap-6 lg:grid-cols-3">
            @csrf
            @method('PUT')

            <div class="lg:col-span-2">
                <fieldset class="rounded-xl border border-slate-200 bg-white p-6">
                    <legend class="px-1 text-sm font-bold text-slate-900">Pick a new time slot</legend>

                    <div class="space-y-5">
                        @foreach ($availability as $date => $slots)
                            <div>
                                <h2 class="text-sm font-semibold text-slate-800">
                                    {{ $dayLabel($date) }}
                                    @if ($date === $appointment->scheduled_at?->toDateString())
                                        <span class="ml-1 text-xs font-normal text-slate-500">(currently booked day)</span>
                                    @endif
                                </h2>

                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($slots as $slot)
                                        @php
                                            $alreadyMine = in_array($slot->id, $bookedSlotIds[$date] ?? [], true);
                                            $isCurrent = (int) $appointment->time_slot_id === (int) $slot->id;
                                        @endphp
                                        <li>
                                            <label class="block cursor-pointer">
                                                <input type="radio" name="time_slot_id" value="{{ $slot->id }}"
                                                       class="peer sr-only"
                                                       @if ($alreadyMine && ! $isCurrent) disabled @endif>
                                                <span class="block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700
                                                             peer-checked:border-blue-700 peer-checked:bg-blue-50 peer-checked:ring-1 peer-checked:ring-blue-700
                                                             @if ($isCurrent) border-slate-400 bg-slate-100 @endif
                                                             @if ($alreadyMine && ! $isCurrent) opacity-50 @endif">
                                                    <span class="font-medium">{{ $slot->formatTime($slot->start_time) }}</span>
                                                    <span class="block text-xs text-slate-500">
                                                        @if ($isCurrent)
                                                            Your current slot
                                                        @elseif ($alreadyMine)
                                                            Already booked
                                                        @else
                                                            {{ $slot->remainingCapacity() }}/{{ $slot->capacity }} left
                                                        @endif
                                                    </span>
                                                </span>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>

                    @error('time_slot_id')
                        <p class="mt-4 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </fieldset>
            </div>

            <aside>
                <div class="sticky top-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="font-bold text-slate-900">Confirm the change</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        You will be issued a new queue number for the new day. Your old number and
                        your old slot are released at the same time.
                    </p>

                    <button type="submit"
                            class="mt-5 w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">
                        Move my appointment
                    </button>

                    <a href="{{ route('client.appointments.show', $appointment) }}"
                       class="mt-2 block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Keep current slot
                    </a>
                </div>
            </aside>
        </form>
    @endif
@endsection