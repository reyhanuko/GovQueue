@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Client notifications</h1>
            <p class="mt-1 text-sm text-slate-600">
                Every confirmation, reminder, move, and cancellation attempted, by channel.
            </p>
        </div>

        <a href="{{ route('admin.dashboard') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Back to admin
        </a>
    </div>

    @if ($smsWarning)
        <div role="status" class="mt-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>SMS gateway &ldquo;{{ $smsGateway }}&rdquo;:</strong> {{ $smsWarning }}
        </div>
    @endif

    <dl class="mt-5 flex flex-wrap gap-3">
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sent</dt>
            <dd class="text-2xl font-bold text-emerald-700">{{ $counts['sent'] }}</dd>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Queued</dt>
            <dd class="text-2xl font-bold text-slate-700">{{ $counts['queued'] }}</dd>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Failed</dt>
            <dd class="text-2xl font-bold text-rose-700">{{ $counts['failed'] }}</dd>
        </div>
    </dl>

    <form method="GET" action="{{ route('admin.notifications.index') }}" class="mt-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div>
            <label for="status" class="block text-sm font-medium text-slate-700">Status</label>
            <select id="status" name="status"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">Any</option>
                @foreach (['queued', 'sent', 'failed'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="channel" class="block text-sm font-medium text-slate-700">Channel</label>
            <select id="channel" name="channel"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">Any</option>
                @foreach (['email', 'sms', 'in_app'] as $channel)
                    <option value="{{ $channel }}" @selected(($filters['channel'] ?? '') === $channel)>{{ ucfirst(str_replace('_', ' ', $channel)) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="type" class="block text-sm font-medium text-slate-700">Type</label>
            <select id="type" name="type"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">Any</option>
                @foreach (['appointment.confirmed', 'appointment.reminder', 'appointment.rescheduled', 'appointment.cancelled'] as $type)
                    <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str_replace('appointment.', '', $type) }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Filter
        </button>

        @if (array_filter($filters))
            <a href="{{ route('admin.notifications.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
                Clear
            </a>
        @endif
    </form>

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
                    <tr>
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
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $log->status->badgeClasses() }}">
                                {{ $log->status->label() }}
                            </span>
                        </td>
                        <td class="max-w-md px-4 py-3 text-xs text-slate-600">
                            {{ $log->error_message ?: $log->subject }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                            No notifications have been attempted yet.
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