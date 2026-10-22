@extends('layouts.app')

@section('title', 'Services')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Services</h1>
            <p class="mt-1 text-sm text-slate-500">Manage the services clients can book. Each service belongs to one service window.</p>
        </div>
        <a href="{{ route('admin.services.create') }}"
           class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Add service
        </a>
    </div>

    <form method="GET" action="{{ route('admin.services.index') }}"
          class="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div class="min-w-[12rem] flex-1">
            <label for="q" class="block text-xs font-medium text-slate-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Service name"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="window" class="block text-xs font-medium text-slate-700">Window</label>
            <select id="window" name="window"
                    class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                <option value="">All windows</option>
                @forelse ($windows as $window)
                    <option value="{{ $window->id }}" @selected((string) ($filters['window'] ?? '') === (string) $window->id)>
                        {{ $window->name }}
                    </option>
                @empty
                    <option value="" disabled>No windows configured</option>
                @endforelse
            </select>
        </div>

        <div>
            <label for="status" class="block text-xs font-medium text-slate-700">State</label>
            <select id="status" name="status"
                    class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                <option value="">All</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
        </div>

        <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Filter
        </button>

        @if (array_filter($filters))
            <a href="{{ route('admin.services.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Clear filters
            </a>
        @endif
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Configured services</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-3 py-2">Service</th>
                    <th scope="col" class="px-3 py-2">Window</th>
                    <th scope="col" class="px-3 py-2">Fee</th>
                    <th scope="col" class="px-3 py-2">Duration</th>
                    <th scope="col" class="px-3 py-2">
                            <span title="Every slot template on this service, and how many are active. Only active ones generate bookable slots.">Slot templates</span>
                        </th>
                        <th scope="col" class="px-3 py-2">
                            <span title="Bookings for a date from today onward, not yet cancelled or completed. This is the same figure the dashboard calls upcoming.">Upcoming</span>
                        </th>
                        <th scope="col" class="px-3 py-2">
                            <span title="Every appointment ever made for this service, including completed and cancelled. Not comparable with Upcoming.">All time</span>
                        </th>
                        <th scope="col" class="px-3 py-2">State</th>
                    <th scope="col" class="px-3 py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($services as $service)
                    <tr>
                        <th scope="row" class="px-3 py-3 text-left font-medium text-slate-800">
                            {{ $service->name }}
                            <span class="block font-mono text-xs font-normal text-slate-500">{{ $service->code }}</span>
                        </th>
                        <td class="px-3 py-3">
                            @if ($service->window)
                                {{ $service->window->name }}
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    Unassigned
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            {{ $service->fee > 0 ? '₱'.number_format((float) $service->fee, 2) : 'Free' }}
                        </td>
                        <td class="px-3 py-3">{{ $service->duration_minutes }} min</td>
                        <td class="px-3 py-3">
                            @if ($service->slot_templates_count === 0)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">None</span>
                            @else
                                {{ $service->slot_templates_count }}
                                {{-- Only active templates generate slots, so the bare
                                     total said nothing about whether this service can
                                     actually be booked. --}}
                                @if ($service->active_slot_templates_count !== $service->slot_templates_count)
                                    <span class="block text-xs text-slate-600">
                                        {{ $service->active_slot_templates_count }} active
                                    </span>
                                @endif
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            {{ $service->upcoming_appointments_count }}
                            @if ($service->appointments_count > 0 && $service->upcoming_appointments_count === 0)
                                <span class="block text-xs text-slate-600">none left</span>
                            @endif
                        </td>
                        <td class="px-3 py-3">{{ $service->appointments_count }}</td>
                        <td class="px-3 py-3">
                            @if ($service->is_active)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.templates.index', $service) }}"
                                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Slots
                                </a>
                                <a href="{{ route('admin.services.edit', $service) }}"
                                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Edit
                                </a>
                                @php $blocker = $deletionBlockers[$service->id] ?? null; @endphp

                                {{--
                                    The confirmation used to promise "X and its slot
                                    templates will be removed. Bookings already made are
                                    kept." and then the request failed with "cannot
                                    delete, 34 appointments reference it" - the promise
                                    was in this template and the rule was in the service
                                    layer, so they could not agree. Both now ask
                                    ScheduleAdminService::deletionBlockerForService().
                                --}}
                                @if ($blocker)
                                    <x-blocked-delete :blocker="$blocker" :id="$service->id" />
                                @else
                                    <form method="POST" action="{{ route('admin.services.destroy', $service) }}"
                                          onsubmit="return window.govqueueConfirm
                                              ? window.govqueueConfirm(this, 'Delete this service?',
                                                  {!! e(json_encode($service->name.' and its '.$service->slot_templates_count.' slot template(s) will be removed. Nothing is booked into it, so no client is affected.')) !!})
                                              : confirm('Delete this service? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                            Delete
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center">
                            <svg class="mx-auto h-8 w-8 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/>
                            </svg>
                            <p class="mt-2 text-sm font-medium text-slate-700">
                                @if (array_filter($filters))
                                    No services match these filters.
                                @else
                                    No services yet.
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                @if (array_filter($filters))
                                    <a href="{{ route('admin.services.index') }}" class="text-blue-700 underline">Clear filters</a> to see all.
                                @else
                                    <a href="{{ route('admin.services.create') }}" class="text-blue-700 underline">Add the first service</a>.
                                @endif
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-slate-500">
        Showing {{ $services->count() }} {{ \Illuminate\Support\Str::plural('service', $services->count()) }}.{{ $services->count() >= 50 ? ' Narrow the search if the list is long.' : '' }}
    </p>
@endsection
