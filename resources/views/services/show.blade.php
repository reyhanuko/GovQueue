@extends('layouts.app')

@section('title', $service->name)

@section('content')
    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('services.index') }}" class="text-blue-700 hover:underline">All services</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">{{ $service->name }}</span>
    </nav>

    <div class="mt-4 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">{{ $service->name }}</h1>
                        <p class="mt-1 font-mono text-xs text-slate-400">{{ $service->code }}</p>
                    </div>
                    @if ($service->fee > 0)
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">
                            &#8369;{{ number_format((float) $service->fee, 2) }}
                        </span>
                    @else
                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-700">Free</span>
                    @endif
                </div>

                <p class="mt-4 text-sm leading-relaxed text-slate-600">{{ $service->description }}</p>

                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service window</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $service->window->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Typical processing time</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-800">{{ $service->duration_minutes }} minutes</dd>
                    </div>
                </dl>
            </div>

            @if ($service->requirementsList())
                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-6">
                    <h2 class="font-bold text-slate-900">What to bring</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Bring all of these. Incomplete requests are turned away, which costs you another trip.
                    </p>
                    <ul class="mt-3 space-y-2">
                        @foreach ($service->requirementsList() as $requirement)
                            <li class="flex items-start gap-2 text-sm text-slate-700">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-700" aria-hidden="true"></span>
                                {{ $requirement }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-6">
                <h2 class="font-bold text-slate-900">Available time slots</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Slots are released up to {{ config('govqueue.booking_horizon_days') }} days in advance.
                    Booking one reserves your place in that slot's queue.
                </p>

                @if ($availability->isEmpty())
                    <div class="mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                        <p class="text-sm font-medium text-slate-700">No open slots in the coming weeks.</p>
                        <p class="mt-1 text-sm text-slate-500">
                            This service is fully booked, or the window is closed. Walk-ins are still accommodated
                            through the office's existing process.
                        </p>
                    </div>
                @else
                    {{-- The grid is a preview. A signed-in client can pick a slot on
                         the booking screen; a guest is sent to sign in first. --}}
                    @php $canBookHere = auth()->check() && auth()->user()->isClient(); @endphp

                    <div class="mt-4 space-y-4">
                        @foreach ($availability as $date => $slots)
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800">{{ $dayLabel($date) }}</h3>
                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($slots as $slot)
                                        <li>
                                            @if ($canBookHere)
                                                <a href="{{ route('client.booking.create', $service) }}?date={{ $date }}"
                                                   class="block rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800 hover:border-blue-400 hover:bg-blue-100"
                                                   title="{{ $slot->remainingCapacity() }} of {{ $slot->capacity }} places left">
                                                    {{ $slot->formatTime($slot->start_time) }}
                                                    <span class="block text-xs">
                                                        {{ $slot->remainingCapacity() }}/{{ $slot->capacity }} left
                                                    </span>
                                                </a>
                                            @else
                                                <span class="block rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700"
                                                      title="{{ $slot->remainingCapacity() }} of {{ $slot->capacity }} places left">
                                                    {{ $slot->formatTime($slot->start_time) }}
                                                    <span class="block text-xs">
                                                        {{ $slot->remainingCapacity() }}/{{ $slot->capacity }} left
                                                    </span>
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        @if ($canBookHere)
                            Choose a day above to continue, or use <strong>Book this service</strong>
                            to see every open slot.
                        @else
                            Sign in as a client to reserve one of these slots.
                        @endif
                    </p>
                @endif
            </div>
        </div>

        <aside class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="font-bold text-slate-900">Ready to book?</h2>

                @auth
                    @if (auth()->user()->isClient())
                        <p class="mt-2 text-sm text-slate-600">
                            Pick a time slot above. You will get a queue number straight away.
                        </p>

                        <a href="{{ route('client.booking.create', $service) }}"
                           class="mt-4 block rounded-lg bg-blue-700 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-blue-800">
                            Book this service
                        </a>

                        @if ($nextSlot)
                            <p class="mt-2 text-center text-xs text-slate-500">
                                Next open slot {{ $nextSlot->start_at->format('D, M d g:i A') }}
                            </p>
                        @endif
                    @else
                        {{-- Staff and administrators have no client booking flow.
                             The booking route is behind role:client, so linking to
                             it would land on a bare 403. --}}
                        <p class="mt-2 text-sm text-slate-600">
                            You are signed in as {{ auth()->user()->name }} ({{ auth()->user()->role->label() }}).
                            Client bookings are made from a client account.
                        </p>
                        <span title="Appointments are booked from a client account. Staff and administrator accounts cannot book."
                              class="mt-4 block cursor-not-allowed rounded-lg border border-slate-300 bg-slate-100 px-4 py-2.5 text-center text-sm font-semibold text-slate-500"
                              aria-disabled="true">
                            Client accounts only
                        </span>
                        <a href="{{ route('dashboard') }}"
                           class="mt-2 block rounded-lg bg-blue-700 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-blue-800">
                            Go to my dashboard
                        </a>
                    @endif
                @else
                    <p class="mt-2 text-sm text-slate-600">
                        Create a free client account to reserve a slot and get a queue number.
                    </p>

                    <a href="{{ route('login') }}"
                       class="mt-4 block rounded-lg bg-blue-700 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-blue-800">
                        Sign in to book
                    </a>

                    <a href="{{ route('register') }}"
                       class="mt-2 block rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Create an account
                    </a>
                @endauth
            </div>

            @if ($nextSlot)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Next open slot</p>
                    <p class="mt-1 text-lg font-bold text-emerald-900">
                        {{ $nextSlot->start_at->format('D, M d') }}
                    </p>
                    <p class="text-sm text-emerald-800">{{ $nextSlot->timeRangeLabel() }}</p>
                    <p class="mt-1 text-xs text-emerald-700">
                        {{ $nextSlot->remainingCapacity() }} of {{ $nextSlot->capacity }} places left
                    </p>
                </div>
            @endif

            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <h2 class="text-sm font-bold text-amber-900">Priority clients</h2>
                <p class="mt-1 text-xs leading-relaxed text-amber-800">
                    Senior citizens (RA 9994), persons with disability (RA 10754), and pregnant clients are
                    reordered ahead of the general queue <em>after staff verify</em> your claim at the counter.
                    Verification is a human decision; the system never checks your status against an
                    external database.
                </p>
            </div>
        </aside>
    </div>
@endsection