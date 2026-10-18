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

            {{-- One line, because the same word means something else in this app.

                 "Notification" in the admin console is a record of a delivery
                 attempt by channel; this is the message itself. A client in-app
                 message appears in both places - here as the client's copy, and
                 in the admin delivery log as the attempt that delivered it.
                 That is correct, but invisible unless it is stated. --}}
            <p class="mt-3 max-w-3xl rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                These are messages about your own account, kept here whether or not you can receive
                email or text. Staff also have a separate <strong>delivery log</strong>, which
                records every attempt to reach a client by email, SMS or this inbox.
            </p>

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

        {{--
            Filter and total.

            The total is here because a paginated list that never says how long
            it is makes page one look like the whole thing. Under a filter the
            denominator stays the full inbox - "5 of 240" - because a total
            taken from the filtered query would just read "5 of 5".
        --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
            {{-- Not role="status". A live region that is already correct at load
                 time announces nothing, but this one also re-fires after the
                 filter navigation, and F-22's whole point was that two
                 role="status" nodes made a screen reader say the same sentence
                 twice. The count is static content here, so it should not be a
                 live region at all. --}}
            <p class="text-sm text-slate-600">
                @if ($unreadOnly)
                    Showing <strong>{{ $notifications->total() }}</strong> unread of
                    {{ $totalCount }} message{{ $totalCount === 1 ? '' : 's' }}.
                @else
                    {{ $totalCount }} message{{ $totalCount === 1 ? '' : 's' }} in total.
                    @if ($unreadCount > 0)
                        {{ $unreadCount }} unread.
                    @endif
                @endif
            </p>

            @if ($unreadCount > 0 || $unreadOnly)
                @if ($unreadOnly)
                    <a href="{{ route('notifications.index') }}"
                       class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Show all messages
                    </a>
                @else
                    <a href="{{ route('notifications.index', ['unread' => 1]) }}"
                       class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Unread only ({{ $unreadCount }})
                    </a>
                @endif
            @endif
        </div>

        {{-- Severity, back office only: an ops level a client message does not
             have, so offering it to a client would offer three filters that all
             return nothing. "Is anything on fire" is the first question, and it
             had no way to be asked. --}}
        @if ($isBackOffice)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Severity</span>

                @foreach ([null => 'All', 'critical' => 'Critical', 'warning' => 'Warning', 'info' => 'Info'] as $value => $label)
                    <a href="{{ route('notifications.index', array_filter(['unread' => $unreadOnly ?: null, 'level' => $value])) }}"
                       @class([
                           'rounded-full px-3 py-1 text-xs font-semibold',
                           'bg-slate-900 text-white' => $level === $value,
                           'border border-slate-300 text-slate-700 hover:bg-slate-50' => $level !== $value,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        @endif

        <ul class="mt-6 space-y-2">
            {{--
                One divider, above the first alert on the page. The controller
                orders critical and warning to the top, so a red or amber row
                appearing halfway down the list can only mean it is unread
                attention that needs dealing with - which is exactly the thing
                the ordering was for, and it deserves to be labelled rather than
                left for the reader to interpret from a badge colour.
            --}}
            @if ($attentionCount > 0)
                <li class="flex items-center gap-2 pt-1 text-sm font-semibold text-slate-700">
                    <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
                    Needs attention ({{ $attentionCount }})
                </li>
            @endif

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

                            {{--
                                Both times, deliberately. "2 days ago" cannot be
                                pinned to a particular appointment - somebody
                                checking whether a reminder went out before a
                                specific slot has to convert it in their head. The
                                relative form leads because it is the one that
                                answers "is this recent"; the absolute one is the
                                title attribute for when it is not.
                            --}}
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
                                &middot;
                                <time datetime="{{ $notification->created_at->toIso8601String() }}"
                                      title="{{ $notification->created_at->format('j M Y, g:i A') }}">
                                    {{ $notification->created_at->diffForHumans() }}
                                </time>
                            </p>
                        </div>

                        {{--
                            One label, not two. "Open" for unread and "View"
                            for read describes the same link with two different
                            names, so somebody scanning the list has to learn
                            that they mean the same thing. Read state is already
                            carried by the dot and the border.
                        --}}
                        <div class="flex shrink-0 items-center gap-2">
                            <a href="{{ route('notifications.read', $notification->id) }}"
                               class="rounded-lg border px-3 py-2 text-xs font-semibold hover:bg-slate-50
                                      {{ $isUnread ? 'border-blue-300 bg-blue-50 text-blue-800' : 'border-slate-300 text-slate-700' }}">
                                Open
                                <span class="sr-only">: {{ $data['title'] ?? 'this message' }}</span>
                            </a>

                            {{--
                                A 24px+ target with a real icon, matching the
                                toast's dismiss button.

                                This was a "&times;" character at px-2 py-1.5
                                text-xs - roughly a 16px hit area, thin, and
                                grey on white, against WCAG 2.2's 24px minimum.
                                That exact defect was fixed for the toast two
                                commits earlier; it was left here, wrapped around
                                a permanent delete that cannot be undone. The
                                confirm dialog stays, because the action is
                                destructive.
                            --}}
                            <form method="POST" action="{{ route('notifications.destroy', $notification->id) }}"
                                  onsubmit="return window.govqueueConfirm
                                      ? window.govqueueConfirm(this, 'Remove this message?', @js($data['title'] ?? 'This message') + ' will be deleted from your inbox. It cannot be recovered.')
                                      : confirm('Remove this message from your inbox? It cannot be recovered.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-300 text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700"
                                        aria-label="Remove this message: {{ $data['title'] ?? 'Update' }}">
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
            @empty
                <li class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-12 text-center">
                    {{-- Two different questions, so two different answers. With the
                         unread filter on, "no messages yet" would be a lie: the
                         inbox is full, they are just all read. --}}
                    @if ($unreadOnly)
                        <p class="text-sm font-medium text-slate-700">Nothing unread.</p>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ $totalCount }} message{{ $totalCount === 1 ? '' : 's' }} in your inbox,
                            all of {{ $totalCount === 1 ? 'it' : 'them' }} read.
                        </p>
                        <a href="{{ route('notifications.index') }}"
                           class="mt-4 inline-block rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Show all messages
                        </a>
                    @else
                        <p class="text-sm font-medium text-slate-700">No messages yet.</p>
                        <p class="mt-1 text-sm text-slate-500">
                            @if ($canFollowAppointments)
                                When you book an appointment, or when its time changes, it will appear here.
                            @else
                                Queue alerts will appear here.
                            @endif
                        </p>
                    @endif
                </li>
            @endforelse
        </ul>

        @if ($notifications->hasPages())
            <div class="mt-6">{{ $notifications->links() }}</div>
        @endif
    </div>
@endsection