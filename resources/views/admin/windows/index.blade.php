@extends('layouts.app')

@section('title', 'Service Windows')

@section('content')
    @include('admin.partials.nav')

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Service windows</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                The counters clients are directed to. A window that still has services or staff cannot be
                deleted &mdash; deactivate it instead, which hides it from clients and keeps the history.
            </p>
        </div>
        <a href="{{ route('admin.windows.create') }}"
           class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Add window
        </a>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Service windows with service and staff counts</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-2">Window</th>
                    <th scope="col" class="px-4 py-2">Code</th>
                    <th scope="col" class="px-4 py-2">Avg. service</th>
                    <th scope="col" class="px-4 py-2">Services</th>
                    <th scope="col" class="px-4 py-2">Staff</th>
                    <th scope="col" class="px-4 py-2">State</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($windows as $window)
                    <tr>
                        <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                            {{ $window->name }}
                            @if ($window->description)
                                <span class="block text-xs font-normal text-slate-500">{{ $window->description }}</span>
                            @endif
                        </th>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $window->code }}</td>
                        <td class="px-4 py-3">{{ $window->avg_service_minutes }} min</td>
                        <td class="px-4 py-3">{{ $window->services_count }}</td>
                        <td class="px-4 py-3">{{ $window->staff_count }}</td>
                        <td class="px-4 py-3">
                            @if ($window->is_active)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.windows.edit', $window) }}"
                                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Edit
                                </a>

                                <form method="POST" action="{{ route('admin.windows.destroy', $window) }}"
                                      onsubmit="return confirm('Delete {{ $window->name }}? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-500">
                            No service windows yet.
                            <a href="{{ route('admin.windows.create') }}" class="text-blue-700 underline">Create the first one</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
