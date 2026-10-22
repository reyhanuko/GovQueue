@extends('layouts.app')

@section('title', 'Service Windows')

@section('content')
    {{-- Breadcrumb --}}
    <nav aria-label="Breadcrumb" class="mb-4">
        <ol class="flex flex-wrap items-center gap-x-1 gap-y-1 text-sm text-slate-500">
            <li>
                <a href="{{ route('admin.dashboard') }}"
                   class="hover:text-slate-700 hover:underline">Dashboard</a>
            </li>
            <li aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 text-slate-400">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                </svg>
            </li>
            <li class="font-medium text-slate-900">Service Windows</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Service windows</h1>
            <p class="mt-1 text-sm text-slate-500">
                The counters clients are directed to. Deactivate rather than delete to preserve history.
            </p>
        </div>
        <a href="{{ route('admin.windows.create') }}"
           class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2
                  text-sm font-semibold text-white hover:bg-indigo-700">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-4 w-4">
                <path d="M8.75 3.75a.75.75 0 0 0-1.5 0v3.5h-3.5a.75.75 0 0 0 0 1.5h3.5v3.5a.75.75 0 0 0 1.5 0v-3.5h3.5a.75.75 0 0 0 0-1.5h-3.5v-3.5Z"/>
            </svg>
            Add window
        </a>
    </div>

    {{-- Count header --}}
    <p class="mt-5 text-xs font-semibold uppercase tracking-widest text-slate-400">
        {{ $windows->count() }} {{ Str::plural('window', $windows->count()) }}
    </p>

    <div class="mt-2 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Service windows with service and staff counts</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Window</th>
                    <th scope="col" class="px-4 py-3">Code</th>
                    <th scope="col" class="px-4 py-3">Avg. service</th>
                    <th scope="col" class="px-4 py-3">Services</th>
                    <th scope="col" class="px-4 py-3">Staff</th>
                    <th scope="col" class="px-4 py-3">State</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($windows as $window)
                    <tr class="transition-colors hover:bg-slate-50/70">
                        <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                            {{ $window->name }}
                            @if ($window->description)
                                <span class="block text-xs font-normal text-slate-500">{{ $window->description }}</span>
                            @endif
                        </th>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $window->code }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $window->avg_service_minutes }} min</td>

                        {{-- Services count — rose badge when zero so the gap is impossible to miss --}}
                        <td class="px-4 py-3">
                            @if ($window->services_count === 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-100
                                             px-2 py-0.5 text-xs font-semibold text-rose-700">
                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                    None
                                </span>
                            @else
                                {{ $window->services_count }}
                            @endif
                        </td>

                        {{-- Staff count — rose badge when zero --}}
                        <td class="px-4 py-3">
                            @if ($window->staff_count === 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-100
                                             px-2 py-0.5 text-xs font-semibold text-rose-700">
                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                    None
                                </span>
                            @else
                                {{ $window->staff_count }}
                            @endif
                        </td>

                        {{-- Active / Inactive dot badge --}}
                        <td class="px-4 py-3">
                            @if ($window->is_active)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100
                                             px-2 py-0.5 text-xs font-medium text-emerald-800">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    Active
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100
                                             px-2 py-0.5 text-xs font-medium text-slate-600">
                                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                    Inactive
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.windows.edit', $window) }}"
                                   class="rounded-lg border border-slate-300 px-3 py-1.5
                                          text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Edit
                                </a>

                                @php $blocker = $deletionBlockers[$window->id] ?? null; @endphp

                                @if ($blocker)
                                    <x-blocked-delete :blocker="$blocker" :id="$window->id" />
                                @else
                                    <form method="POST" action="{{ route('admin.windows.destroy', $window) }}"
                                          onsubmit="return window.govqueueConfirm
                                              ? window.govqueueConfirm(this, 'Delete this window?',
                                                  {!! e(json_encode($window->name.' will be removed. It has no services and no staff assigned, so nothing else goes with it.')) !!})
                                              : confirm('Delete this window? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="rounded-lg border border-rose-300 px-3 py-1.5
                                                       text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                            Delete
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center">
                            <svg class="mx-auto h-10 w-10 text-slate-300" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                      d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                            </svg>
                            <p class="mt-3 text-sm font-medium text-slate-700">No service windows yet.</p>
                            <p class="mt-1 text-xs text-slate-500">
                                <a href="{{ route('admin.windows.create') }}"
                                   class="text-indigo-600 hover:underline">Create the first window</a>
                                to get started. Everything else — services, staff, slots — hangs off one.
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
