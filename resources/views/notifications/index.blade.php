@extends('layouts.app')

@section('title', 'Messages')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Messages</h1>
                <p class="mt-1 text-sm text-slate-600">
                    @if ($canFollowAppointments)
                        Everything the office has sent you about your appointments, kept here
                        whether or not you can receive email or text.
                    @else
                        Alerts about the queues and windows you are responsible for.
                    @endif
                </p>
            </div>

            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Mark all as read ({{ $unreadCount }})
                    </button>
                </form>
            @endif
        </div>

        <ul class="mt-6 space-y-2">
            @forelse ($notifications as $notification)
                @php
                    $data = $notification->data;
                    $isUnread = $notification->read_at === null;
                @endphp

                <li @class([
                    'rounded-xl border bg-white',
                    'border-blue-300 ring-1 ring-blue-200' => $isUnread,
                    'border-slate-200' => ! $isUnread,
                ])>
                    <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 font-semibold text-slate-900">
                                @if ($isUnread)
                                    {{-- Marked aria-hidden: the bold weight already
                                         signals it, and a screen reader reading
                                         "new" before every message would be noise. --}}
                                    <span class="h-2 w-2 shrink-0 rounded-full bg-blue-600"
                                          title="Unread" aria-hidden="true"></span>
                                @endif
                                {{ $data['title'] ?? 'Update' }}
                            </p>

                            @php
                                /*
                                 * One template, two payload shapes. A client message
                                 * carries an appointment reference and queue number;
                                 * an ops message carries a severity and a link into
                                 * the screen where something can be done about it.
                                 * Dispatching on the payload's own `type` rather
                                 * than on the reader's role keeps a row looking
                                 * the same if it is ever forwarded.
                                 */
                                $isOps = ($data['type'] ?? '') === 'ops';
                                $level = $data['level'] ?? 'info';
                            @endphp

                            <p class="mt-1 text-sm text-slate-600">
                                {{ $isOps
                                    ? \App\Notifications\OpsNotification::summaryFor($data)
                                    : \App\Notifications\AppointmentNotification::summaryFor($data) }}
                            </p>

                            <p class="mt-1 font-mono text-xs text-slate-400">
                                @if ($isOps)
                                    @if ($level !== 'info')
                                        <span @class([
                                            'font-sans font-semibold uppercase tracking-wide',
                                            'text-red-600' => $level === 'critical',
                                            'text-amber-600' => $level === 'warning',
                                        ])>{{ $level }}</span>
                                        &middot;
                                    @endif
                                @else
                                    {{ $data['reference'] ?? '' }}
                                    @isset($data['queue_number'])
                                        &middot; queue {{ $data['queue_number'] }}
                                    @endisset
                                @endif
                                &middot; {{ $notification->created_at->diffForHumans() }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <a href="{{ route('notifications.read', $notification->id) }}"
                               class="rounded-lg border px-3 py-1.5 text-xs font-semibold hover:bg-slate-50
                                      {{ $isUnread ? 'border-blue-300 bg-blue-50 text-blue-800' : 'border-slate-300 text-slate-700' }}">
                                {{ $isUnread ? 'Open' : 'View' }}
                            </a>

                            <form method="POST" action="{{ route('notifications.destroy', $notification->id) }}"
                                  onsubmit="return window.govqueueConfirm
                                      ? window.govqueueConfirm(this, 'Remove this message?', @js($data['title'] ?? 'This message') + ' will be deleted from your inbox. It cannot be recovered.')
                                      : confirm('Remove this message from your inbox? It cannot be recovered.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-semibold text-slate-500 hover:bg-slate-50"
                                        aria-label="Remove this message">
                                    &times;
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
            @empty
                <li class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-12 text-center">
                    <p class="text-sm font-medium text-slate-700">No messages yet.</p>
                    <p class="mt-1 text-sm text-slate-500">
                        @if ($canFollowAppointments)
                            When you book an appointment, or when its time changes, it will appear here.
                        @else
                            Queue alerts will appear here.
                        @endif
                    </p>
                </li>
            @endforelse
        </ul>

        @if ($notifications->hasPages())
            <div class="mt-6">{{ $notifications->links() }}</div>
        @endif
    </div>
@endsection