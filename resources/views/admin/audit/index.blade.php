@extends('layouts.app')

@section('title', 'Audit Trail')

@section('content')
    @include('admin.partials.nav')

    <h1 class="text-2xl font-bold text-slate-900">Audit trail</h1>
    <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Every configuration change, who made it, and the values before and after. Client bookings and
        queue actions are recorded too, so one screen covers the whole system.
    </p>

    <form method="GET" action="{{ route('admin.audit.index') }}"
          class="mt-6 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="action" class="block text-xs font-medium text-slate-700">Action</label>
            <select id="action" name="action"
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                <option value="">All actions</option>
                @foreach ($actions as $action => $total)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>
                        {{ $action }} ({{ $total }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="subject" class="block text-xs font-medium text-slate-700">Record type</label>
            <select id="subject" name="subject"
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                <option value="">All types</option>
                @foreach ($subjects as $subject)
                    <option value="{{ $subject }}" @selected(($filters['subject'] ?? '') === $subject)>
                        {{ $subject }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="from" class="block text-xs font-medium text-slate-700">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="to" class="block text-xs font-medium text-slate-700">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div class="flex items-end gap-3">
            <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">
                Filter
            </button>
            @if (array_filter($filters))
                <a href="{{ route('admin.audit.index') }}" class="py-2 text-sm text-blue-700 hover:underline">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Recorded system actions</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-2">When</th>
                    <th scope="col" class="px-4 py-2">Who</th>
                    <th scope="col" class="px-4 py-2">Action</th>
                    <th scope="col" class="px-4 py-2">Record</th>
                    <th scope="col" class="px-4 py-2">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr class="align-top">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                            {{ $log->created_at->format('j M Y H:i') }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $log->user?->name ?? 'System' }}
                            @if ($log->ip_address)
                                <span class="block font-mono text-xs text-slate-400">{{ $log->ip_address }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs font-medium text-slate-800">{{ $log->action }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            @if ($log->subject_type)
                                {{ $log->subject_type }} #{{ $log->subject_id }}
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if (filled($log->payload))
                                <details>
                                    <summary class="cursor-pointer text-xs font-medium text-blue-700">
                                        Show payload
                                    </summary>
                                    <pre class="mt-2 max-w-xl overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @else
                                <span class="text-xs text-slate-400">None</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            No entries match these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($logs->hasPages())
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
@endsection
