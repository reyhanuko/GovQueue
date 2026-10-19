@extends('layouts.app')

@section('title', 'Services')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Services</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Moving a service to a different window also moves its generated slots and its upcoming
                appointments, so the staff console keeps showing them on the right board.
            </p>
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

        <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Filter
        </button>

        @if (array_filter($filters))
            <a href="{{ route('admin.services.index') }}" class="px-2 py-2 text-sm text-blue-700 hover:underline">
                Clear
            </a>
        @endif
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Configured services</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
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
                        <td class="px-3 py-3">{{ $service->window?->name ?? 'Unassigned' }}</td>
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
                                                  @js($service->name) + ' and its ' . $service->slot_templates_count . ' slot template(s) will be removed. Nothing is booked into it, so no client is affected.')
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
                        <td colspan="9" class="px-4 py-10 text-center text-slate-500">
                            @if (array_filter($filters))
                                No services match these filters.
                                <a href="{{ route('admin.services.index') }}" class="text-blue-700 underline">Clear them</a>.
                            @else
                                No services yet.
                                <a href="{{ route('admin.services.create') }}" class="text-blue-700 underline">Add the first one</a>.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-slate-500">
        Showing {{ $services->count() }} service(s).{{ $services->count() >= 50 ? ' Narrow the search if the list is long.' : '' }}
    </p>
@endsection
