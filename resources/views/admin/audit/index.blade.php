@extends('layouts.app')

@section('title', 'Audit Trail')

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
            <li class="font-medium text-slate-900">Audit Trail</li>
        </ol>
    </nav>

    <h1 class="text-2xl font-bold text-slate-900">Audit trail</h1>
    <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Every configuration change, who made it, and the values before and after. Client bookings and
        queue actions are recorded too, so one screen covers the whole system.
    </p>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('admin.audit.index') }}"
          class="mt-6 grid gap-3 rounded-xl border border-slate-200 bg-white p-4
                 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="action" class="block text-xs font-medium text-slate-700">Action</label>
            <select id="action" name="action"
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                           focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                <option value="">All actions</option>
                @foreach ($actions as $action => $total)
                    {{-- The sentence, not the key. The option's value stays the
                         key, so the filter still matches the stored records. --}}
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>
                        {{ \App\Models\AuditLog::sentenceFor($action) }} ({{ $total }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="subject" class="block text-xs font-medium text-slate-700">Record type</label>
            <select id="subject" name="subject"
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                           focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                <option value="">All types</option>
                @foreach ($subjects as $subject)
                    <option value="{{ $subject }}" @selected(($filters['subject'] ?? '') === $subject)>
                        {{ $subject }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Bounded by the first and last dates an entry can carry, so a
             mistyped year shows as an empty trail rather than as "nothing has
             been changed since the system was installed". --}}
        <div>
            <label for="from" class="block text-xs font-medium text-slate-700">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"
                   min="{{ $dateBounds[0] }}" max="{{ $dateBounds[1] }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                          focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <div>
            <label for="to" class="block text-xs font-medium text-slate-700">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"
                   min="{{ $dateBounds[0] }}" max="{{ $dateBounds[1] }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                          focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <div class="flex items-end gap-3">
            <button type="submit"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold
                           text-white hover:bg-indigo-700">
                Filter
            </button>
            @if (array_filter($filters))
                <a href="{{ route('admin.audit.index') }}"
                   class="py-2 text-sm text-indigo-600 hover:underline">Clear</a>
            @endif
        </div>
    </form>

    {{-- Log table --}}
    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Recorded system actions</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
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
                    <tr class="align-top hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                            {{ $log->created_at->format('j M Y H:i') }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $log->user?->name ?? 'System' }}
                            @if ($log->ip_address)
                                <span class="block font-mono text-xs text-slate-500">
                                    {{ $log->ip_address }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{-- In words. The raw key is still in the row, one
                                 click away, for anybody who needs it.

                                 slate-500, not slate-400: the key is small
                                 secondary text and still has to meet the
                                 contrast the rest of the page meets. --}}
                            <span class="text-sm font-medium text-slate-900">
                                {{ $log->actionSentence() }}
                            </span>
                            <span class="block font-mono text-xs text-slate-500">
                                {{ $log->action }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $log->subjectLabel() }}</td>
                        <td class="px-4 py-3">
                            @php $summary = $log->payloadSummary(); @endphp

                            @if ($summary !== [])
                                <ul class="space-y-0.5 text-xs text-slate-700">
                                    @foreach ($summary as $line)
                                        <li>{{ $line }}</li>
                                    @endforeach
                                </ul>

                                {{-- Raw JSON stays one click away for anything the
                                     summary does not render. --}}
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs font-medium text-indigo-600
                                                    hover:underline">
                                        Show raw payload
                                    </summary>
                                    <pre class="mt-2 max-w-xl overflow-x-auto rounded-lg bg-slate-900 p-3
                                                text-xs text-slate-100">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @else
                                <span class="text-xs text-slate-500">Nothing recorded</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="1.5" stroke="currentColor"
                                 class="mx-auto mb-3 h-10 w-10 text-slate-300">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                            </svg>
                            <p class="text-sm font-medium text-slate-600">No entries match these filters.</p>
                            @if (array_filter($filters))
                                <p class="mt-1 text-xs text-slate-400">
                                    <a href="{{ route('admin.audit.index') }}"
                                       class="text-indigo-600 hover:underline">Clear all filters</a>
                                    to see the full trail.
                                </p>
                            @endif
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
