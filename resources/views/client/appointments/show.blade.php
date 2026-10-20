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

    @if ($showLiveStatus)
        <div class="mt-6">
            @livewire('client.queue-status', ['appointment' => $appointment])
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <h2 class="font-bold text-slate-900">Your appointment</h2>

                {{-- When, given the prominence it deserves.

                     The date and the time used to be two more cells in a
                     four-item grid, so the one fact a client actually came here
                     to check looked the same as "Booking type" - and then the
                     date was printed a second time in the queue-number card
                     below, which is why the page read as if it had four dates
                     on it and no date in particular.

                     One combined, prominent block instead. --}}
                <div class="mt-4 flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-lg bg-slate-50 px-4 py-3">
                    <p class="text-lg font-bold text-slate-900">
                        {{ $appointment->scheduledDateLabel() }}
                    </p>
                    <p class="text-lg font-semibold text-blue-800">
                        {{ $appointment->scheduledTimeLabel() }}
                    </p>
                </div>

                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Window</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $appointment->window->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Booking type</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $appointment->source->label() }}</dd>
                    </div>

                    {{--
                        The money, which the booking screen promises and then
                        never mentions again: the fee was on the page the
                        client booked from, and nowhere else - not in the
                        confirmation, not here, and after the transaction not
                        the amount they actually paid either.

                        What is shown depends on where the booking has got to.
                        Before the counter, the fee is what is owed. After it,
                        the transaction is the truth, and it carries its own
                        reference - which is the receipt.
                    --}}
                    @if ($appointment->status !== \App\Enums\AppointmentStatus::Cancelled)
                        @php $receipt = $appointment->transaction->first(); @endphp
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $receipt ? 'Fee paid' : 'Fee' }}
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-slate-800">
                                @if ($receipt)
                                    &#8369;{{ number_format((float) $receipt->fee_paid, 2) }} paid
                                    <span class="block text-xs font-normal text-slate-500">
                                        Receipt {{ $receipt->reference_number }}
                                    </span>
                                @elseif ($appointment->service->fee > 0)
                                    &#8369;{{ number_format((float) $appointment->service->fee, 2) }} payable at the counter
                                @else
                                    Free
                                @endif
                            </dd>
                        </div>
                    @endif
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
                {{-- No date here: it is stated once, above, and repeating it is what made
                     the page read as if it had four dates and no date. --}}
                <p class="mt-2 text-xs text-blue-700">
                    Numbered for the {{ $appointment->scheduledTimeLabel() }} slot at {{ $appointment->window->name }}
                </p>
            </div>

            {{-- What the client may still do about this appointment.

                 A ticket the client has already arrived for is not theirs to
                 change: the service refuses to cancel a checked-in or serving
                 ticket, so offering the button would be offering something
                 that fails. --}}
            @if ($appointment->status->isActive() && ! $appointment->status->isAtTheOffice())
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
            @elseif ($appointment->status->isAtTheOffice())
                {{-- Not closed. This client is at the office, and the live
                     board above is telling them so. Calling it closed - and
                     offering them "Book again" for a service they are
                     standing in the queue for - was telling a client at a
                     window that their appointment had ended.

                     What is said here is only what the board does not say:
                     the board says whether their number has been called. --}}
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="font-bold text-slate-900">This appointment is with the office</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        You are at {{ $appointment->window->name }}, so this one cannot be
                        moved or cancelled from here: the staff at the window will see you.
                    </p>
                    <a href="{{ route('services.index') }}"
                       class="mt-3 block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Book another service
                    </a>
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