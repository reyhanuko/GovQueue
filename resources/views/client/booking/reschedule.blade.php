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
        {{--
            Same Alpine state and the same picker as booking a new appointment.
            Moving an appointment is the same decision - which day, which time -
            and this page used to be a separate copy of it that had drifted: it
            still rendered every day at once and its button was never gated on a
            slot being chosen, which is the exact failure reported on the booking
            page. One picker, used twice, so the two cannot drift again.
        --}}
        <form method="POST" action="{{ route('client.appointments.reschedule.store', $appointment) }}"
              class="mt-6 grid gap-6 lg:grid-cols-3"
              x-data="{
                  selectedSlot: null,
                  selectedDate: null,
                  selectedTime: null,
                  selectSlot(date, time, slotId) {
                      this.selectedDate = date;
                      this.selectedTime = time;
                      this.selectedSlot = slotId;
                  }
              }">
            @csrf
            @method('PUT')

            <div class="min-w-0 space-y-5 lg:col-span-2">
                @include('client.booking._slot_picker', [
                    'legend' => 'Pick a new time slot',
                    'currentAppointment' => $appointment,
                ])
            </div>

            <aside>
                <div class="sticky top-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="font-bold text-slate-900">Confirm the change</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        You will be issued a new queue number for the new day. Your old number and
                        your old slot are released at the same time.
                    </p>

                    {{-- The slot this move is to, next to the button that performs
                         it. Without it the only record of the choice was the
                         highlighted radio, and the panel that commits to it did
                         not mention it at all. --}}
                    <div class="mt-4 rounded-lg bg-slate-50 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            New slot
                        </p>
                        <div x-show="selectedSlot" x-cloak>
                            <p class="mt-0.5 text-sm font-medium text-slate-800">
                                <span x-text="selectedDate"></span>
                                at <span x-text="selectedTime"></span>
                            </p>
                        </div>
                        <div x-show="! selectedSlot" x-cloak>
                            <p class="mt-0.5 text-sm text-slate-500">Not chosen yet</p>
                        </div>
                    </div>

                    <button type="submit"
                            x-bind:disabled="! selectedSlot"
                            class="mt-5 w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                        Move my appointment
                    </button>

                    <p x-show="! selectedSlot" x-cloak class="mt-2 text-xs text-slate-500">
                        Choose a time in the list first.
                    </p>

                    <a href="{{ route('client.appointments.show', $appointment) }}"
                       class="mt-2 block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Keep current slot
                    </a>
                </div>
            </aside>
        </form>
    @endif
@endsection