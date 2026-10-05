@extends('layouts.app')

@section('title', 'Holidays')

@section('content')
    @include('admin.partials.nav')

    <h1 class="text-2xl font-bold text-slate-900">Holidays and closures</h1>
    <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Declaring a holiday hides that date's slots from every booking screen. It does
        <strong>not</strong> cancel appointments already booked, so any affected clients are listed below
        for someone to contact or rebook.
    </p>

    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6" aria-labelledby="add-holiday">
        <h2 id="add-holiday" class="text-lg font-bold text-slate-900">Declare a closure</h2>

        <form method="POST" action="{{ route('admin.holidays.store') }}"
              class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @csrf

            <x-admin.field name="date" type="date" label="Date" required
                          :value="old('date', today()->toDateString())" />

            <x-admin.field name="name" label="Name" required
                          :value="old('name')" placeholder="Foundational Day" />

            <div class="sm:col-span-2">
                <x-admin.field name="notes" label="Notes" :value="old('notes')"
                              placeholder="Optional. For example, a separate non-working day." />
            </div>

            <div class="flex items-end">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                    Save closure
                </button>
            </div>
        </form>
    </section>

    <h2 class="mt-8 text-lg font-bold text-slate-900">Declared closures</h2>

    @if ($holidays->isEmpty())
        <p class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500">
            No closures declared. Clients can book every day the slot templates cover.
        </p>
    @else
        <ul class="mt-3 space-y-3">
            @foreach ($holidays as $holiday)
                @php
                    $booked = $affected[$holiday->id] ?? collect();
                    $isPast = $holiday->date->isPast();
                @endphp

                <li class="rounded-xl border {{ $isPast ? 'border-slate-200 bg-white' : 'border-slate-200 bg-white' }} p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-900">
                                {{ $holiday->name }}
                                <span class="ml-1 font-normal text-slate-500">
                                    {{ $holiday->date->format('l, j F Y') }}
                                </span>
                                @if ($isPast)
                                    <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Past</span>
                                @endif
                            </p>

                            @if ($holiday->notes)
                                <p class="mt-1 text-sm text-slate-600">{{ $holiday->notes }}</p>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('admin.holidays.destroy', $holiday) }}"
                              onsubmit="return confirm('Remove this closure? Clients will be able to book that date again.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                Remove
                            </button>
                        </form>
                    </div>

                    @if ($booked->isNotEmpty())
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3">
                            <p class="text-sm font-semibold text-amber-900">
                                {{ $booked->count() }} client(s) still hold a booking on this date
                            </p>
                            <ul class="mt-2 space-y-1">
                                @foreach ($booked as $appointment)
                                    <li class="text-sm text-amber-900">
                                        {{ $appointment->scheduledTimeLabel() }} &mdash;
                                        <strong>{{ $appointment->user?->name ?? 'Unknown client' }}</strong>
                                        ({{ $appointment->service?->name }})
                                        <span class="font-mono text-xs">{{ $appointment->reference_number }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs text-amber-800">
                                These appointments still exist. Contact or rebook each client.
                            </p>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@endsection
