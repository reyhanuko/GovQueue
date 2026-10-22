@extends('layouts.app')

@section('title', 'Delivery Log')

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
            <li class="font-medium text-slate-900">Delivery Log</li>
        </ol>
    </nav>

    <h1 class="text-2xl font-bold text-slate-900">Client delivery log</h1>
    <p class="mt-1 text-sm text-slate-600">
        Every confirmation, reminder, move, and cancellation attempted, by channel.
    </p>

    @if ($smsWarning)
        <div role="status"
             class="mt-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>SMS gateway &ldquo;{{ $smsGateway }}&rdquo;:</strong> {{ $smsWarning }}
        </div>
    @endif

    {{-- Summary stats --}}
    <dl class="mt-5 flex flex-wrap gap-3">
        <div class="rounded-xl border border-slate-200 bg-white px-5 py-3">
            <dt class="text-xs font-semibold uppercase tracking-widest text-slate-500">Sent</dt>
            <dd class="mt-0.5 text-2xl font-bold text-emerald-700">{{ $counts['sent'] }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-5 py-3">
            <dt class="text-xs font-semibold uppercase tracking-widest text-slate-500">Queued</dt>
            <dd class="mt-0.5 text-2xl font-bold text-slate-700">{{ $counts['queued'] }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-5 py-3">
            <dt class="text-xs font-semibold uppercase tracking-widest text-slate-500">Failed</dt>
            <dd class="mt-0.5 text-2xl font-bold text-rose-700">{{ $counts['failed'] }}</dd>
        </div>
    </dl>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('admin.notifications.index') }}"
          class="mt-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div>
            <label for="status" class="block text-xs font-medium text-slate-700">Status</label>
            <select id="status" name="status"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm
                           focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="">Any</option>
                @foreach (['queued', 'sent', 'failed'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                        {{ ucfirst($status) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="channel" class="block text-xs font-medium text-slate-700">Channel</label>
            <select id="channel" name="channel"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm
                           focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="">Any</option>
                @foreach (['email', 'sms', 'in_app'] as $channel)
                    <option value="{{ $channel }}" @selected(($filters['channel'] ?? '') === $channel)>
                        {{ ucfirst(str_replace('_', ' ', $channel)) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="type" class="block text-xs font-medium text-slate-700">Type</label>
            <select id="type" name="type"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm
                           focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="">Any</option>
                @foreach (['appointment.confirmed', 'appointment.reminder', 'appointment.rescheduled', 'appointment.cancelled'] as $type)
                    <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>
                        {{ str_replace('appointment.', '', $type) }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold
                       text-white hover:bg-indigo-700">
            Filter
        </button>

        @if (array_filter($filters))
            <a href="{{ route('admin.notifications.index') }}"
               class="rounded-lg px-3 py-2 text-sm font-medium text-indigo-600 hover:bg-slate-100">
                Clear
            </a>
        @endif
    </form>

    {{-- Log table --}}
    <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <caption class="sr-only">Notification delivery attempts</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">When</th>
                    <th scope="col" class="px-4 py-3">Type</th>
                    <th scope="col" class="px-4 py-3">Channel</th>
                    <th scope="col" class="px-4 py-3">Destination</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                            {{ $log->created_at->format('M d, g:i A') }}
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-800">
                            {{ str_replace('appointment.', '', $log->type) }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $log->channel->label() }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">
                            {{ $log->destination ?: '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold
                                         {{ $log->status->badgeClasses() }}">
                                {{ $log->status->label() }}
                            </span>
                        </td>
                        <td class="max-w-md px-4 py-3 text-xs text-slate-600">
                            {{ $log->error_message ?: $log->subject }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="1.5" stroke="currentColor"
                                 class="mx-auto mb-3 h-10 w-10 text-slate-300">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                            </svg>
                            {{-- Two different questions, two different answers.
                                 "No notifications attempted yet" is only true when nothing
                                 has ever been sent. With a filter the truth is nothing matches. --}}
                            @if (array_filter($filters))
                                <p class="text-sm font-medium text-slate-600">
                                    No notifications match this filter.
                                </p>
                                <p class="mt-1 text-xs text-slate-400">
                                    <a href="{{ route('admin.notifications.index') }}"
                                       class="text-indigo-600 hover:underline">Clear the filter</a>
                                    to see everything.
                                </p>
                            @else
                                <p class="text-sm font-medium text-slate-600">
                                    No notifications have been attempted yet.
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

    <p class="mt-6 text-xs text-slate-500">
        GovQueue does not guarantee message delivery. A failed attempt is recorded here with the
        reason so staff can follow up with the client through another channel.
    </p>
@endsection