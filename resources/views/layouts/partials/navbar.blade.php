@php
    /*
     * The unread count, read once per request.
     *
     * Rendered on every authenticated page, so it was tempting to query it in
     * each link. One query here instead, and only when signed in: a guest pays
     * nothing, and a signed-in user pays one COUNT rather than one per link.
     *
     * Capped at 99 for display - "99+" is honest about the scale without
     * pushing the header around when an alert storm lands.
     *
     * The preview list rides on the same idea. The bell is a disclosure, not a
     * link, so without this a user would have to leave the page they are on -
     * and often the console they are mid-way through - just to find out whether
     * the badge is worth opening.
     */
    $unreadMessages = auth()->check()
        ? auth()->user()->unreadNotifications()->count()
        : 0;

    /*
     * Five, unread only. Small enough to be a glance rather than a list, and
     * newest first so the thing most likely to matter is on top. Read messages
     * are deliberately excluded: the badge is about what still needs attention,
     * and mixing in what has already been dealt with is what makes a preview
     * longer than the badge can be trusted to summarise.
     */
    $unreadPreview = auth()->check()
        ? auth()->user()->unreadNotifications()->latest()->limit(5)->get()
        : collect();
@endphp

<nav class="border-b border-slate-200 bg-white" aria-label="Main navigation">
    <div class="relative mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-700 text-lg font-black text-white"
                  aria-hidden="true">GQ</span>
            <span class="leading-tight">
                <span class="block text-base font-bold text-slate-900">GovQueue</span>
                <span class="block text-xs text-slate-500">LGU of Tupi, South Cotabato</span>
            </span>
        </a>

        {{--
            The primary links were `hidden md:flex` with no mobile replacement, so
            anyone on a phone had no navigation at all - they could only reach Home,
            Services, or Queue Display by typing the URL. For a municipal service
            where a large share of clients are on a phone, that is not acceptable.

            Below md the links collapse into a native <details> disclosure, which
            needs no JavaScript and stays keyboard operable.
        --}}
        <details class="static md:hidden">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">
                <span class="sr-only">Open main menu</span>
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M3 5.5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 5.5Zm0 4.5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75a.75.75 0 0 1-.75-.75Zm0 4.5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                </svg>
                Menu
                @if ($unreadMessages > 0)
                    <span class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 text-xs font-bold text-white">
                        {{ $unreadMessages > 99 ? '99+' : $unreadMessages }}
                        <span class="sr-only"> unread messages</span>
                    </span>
                @endif
            </summary>

            <div class="absolute inset-x-4 top-full z-40 mt-2 rounded-xl border border-slate-200 bg-white p-2 shadow-lg md:hidden">
                <ul class="space-y-0.5 text-sm font-medium text-slate-700">
                    <li><x-nav-link :href="route('home')" :active="request()->routeIs('home')">Home</x-nav-link></li>
                    <li><x-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')">Services</x-nav-link></li>
                    <li><x-nav-link :href="route('display.index')" :active="request()->routeIs('display.*')">Queue Display</x-nav-link></li>

                    @auth
                        @if (auth()->user()->isAdmin())
                            <li><x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')">Administration</x-nav-link></li>
                            {{-- Administrators may open any window's console, so the link
                                 belongs here too, not only under the staff branch. --}}
                            <li><x-nav-link :href="route('staff.dashboard')" :active="request()->routeIs('staff.*')">Staff Console</x-nav-link></li>
                        @elseif (auth()->user()->isStaff())
                            <li><x-nav-link :href="route('staff.dashboard')" :active="request()->routeIs('staff.*')">Staff Console</x-nav-link></li>
                        @else
                            <li><x-nav-link :href="route('client.appointments.index')" :active="request()->routeIs('client.appointments.*')">My Appointments</x-nav-link></li>
                            <li><x-nav-link :href="route('client.profile.edit')" :active="request()->routeIs('client.profile.*')">Profile</x-nav-link></li>
                        @endif

                        {{-- For every role: the inbox holds different things for
                             different roles, but it is the same place for all of them. --}}
                        <li>
                            <x-nav-link :href="route('notifications.index')" :active="request()->routeIs('notifications.*')">
                                <span class="flex items-center justify-between gap-2">
                                    <span>Messages</span>
                                    @if ($unreadMessages > 0)
                                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 text-xs font-bold text-white">
                                            {{ $unreadMessages > 99 ? '99+' : $unreadMessages }}
                                            <span class="sr-only"> unread</span>
                                        </span>
                                    @endif
                                </span>
                            </x-nav-link>
                        </li>
                    @endauth
                </ul>
            </div>
        </details>

        <div class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex">
            <x-nav-link :href="route('home')" :active="request()->routeIs('home')" class="hover:bg-transparent">Home</x-nav-link>
            <x-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')" class="hover:bg-transparent">Services</x-nav-link>
            <x-nav-link :href="route('display.index')" :active="request()->routeIs('display.*')" class="hover:bg-transparent">Queue Display</x-nav-link>

            @auth
                @if (auth()->user()->isAdmin())
                    <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')" class="hover:bg-transparent">Admin</x-nav-link>
                    {{-- An administrator is allowed to open any window's console, but
                         had no link to it because the staff branch was an elseif. --}}
                    <x-nav-link :href="route('staff.dashboard')" :active="request()->routeIs('staff.*')" class="hover:bg-transparent">Staff Console</x-nav-link>
                @elseif (auth()->user()->isStaff())
                    <x-nav-link :href="route('staff.dashboard')" :active="request()->routeIs('staff.*')" class="hover:bg-transparent">Staff Console</x-nav-link>
                @else
                    <x-nav-link :href="route('client.appointments.index')" :active="request()->routeIs('client.appointments.*')" class="hover:bg-transparent">My Appointments</x-nav-link>
                    <x-nav-link :href="route('client.profile.edit')" :active="request()->routeIs('client.profile.*')" class="hover:bg-transparent">Profile</x-nav-link>
                @endif
            @endauth
        </div>

        <div class="flex items-center gap-2">
            @auth
                {{-- A bell rather than the word "Messages": the unread count is the
                     whole point, and it has to be readable without opening the menu.

                     A disclosure, not a link, so the newest unread messages are
                     readable without leaving the page. Native <details> for the
                     same reasons as the mobile menu below: it opens without
                     script, is keyboard operable for free, and needs no
                     positioning JavaScript to stay on screen when zoomed.

                     "View all messages" stays inside, so without script - or
                     with the panel never opened - there is still a real link to
                     the full inbox rather than a control that does nothing. --}}
                <details class="static shrink-0">
                    <summary class="relative flex cursor-pointer list-none items-center rounded-lg border border-slate-300 p-2 text-slate-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden"
                             @if (request()->routeIs('notifications.*')) aria-current="page" @endif>
                        <span class="sr-only">
                            Messages{{ $unreadMessages > 0 ? ', '.$unreadMessages.' unread' : '' }}
                        </span>
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                        @if ($unreadMessages > 0)
                            {{-- aria-hidden: the sr-only text above already says how
                                 many are unread. Without this the bell announces
                                 as "Messages, 3 unread 3" - the same
                                 double-announcement that F-22 was about, and only
                                 visible in an accessibility tree. --}}
                            <span class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 text-xs font-bold text-white ring-2 ring-white"
                                  aria-hidden="true">
                                {{ $unreadMessages > 99 ? '99+' : $unreadMessages }}
                            </span>
                        @endif
                    </summary>

                    <div class="absolute right-0 top-full z-40 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                        <div class="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $unreadMessages > 0 ? $unreadMessages.' unread' : 'All caught up' }}
                            </p>

                            @if ($unreadMessages > 0)
                                <form method="POST" action="{{ route('notifications.read-all') }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold text-blue-700 hover:text-blue-900 hover:underline">
                                        Mark all read
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if ($unreadPreview->isEmpty())
                            <p class="px-4 py-6 text-center text-sm text-slate-500">
                                Nothing unread.
                            </p>
                        @else
                            <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
                                @foreach ($unreadPreview as $preview)
                                    @php
                                        $previewData = $preview->data;
                                        $previewLevel = $previewData['level'] ?? 'info';
                                    @endphp
                                    <li>
                                        {{-- Goes through notifications.read, so opening a
                                             preview marks it read and follows it in one
                                             step, rather than leaving it unread and
                                             sending the reader back here. --}}
                                        <a href="{{ route('notifications.read', $preview->id) }}"
                                           class="block px-4 py-3 hover:bg-slate-50">
                                            <p class="flex items-start gap-2 text-sm font-semibold text-slate-900">
                                                @if ($previewLevel === 'critical')
                                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-red-600"
                                                          title="Critical" aria-hidden="true"></span>
                                                @elseif ($previewLevel === 'warning')
                                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-amber-500"
                                                          title="Warning" aria-hidden="true"></span>
                                                @endif
                                                <span class="min-w-0">{{ $previewData['title'] ?? 'Update' }}</span>
                                            </p>
                                            <p class="mt-0.5 truncate pl-4 text-xs text-slate-600">
                                                {{ ($previewData['type'] ?? '') === 'ops'
                                                    ? \App\Notifications\OpsNotification::summaryFor($previewData)
                                                    : \App\Notifications\AppointmentNotification::summaryFor($previewData) }}
                                            </p>
                                            <p class="mt-0.5 pl-4 text-xs text-slate-400">
                                                {{ $preview->created_at->diffForHumans() }}
                                            </p>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="border-t border-slate-200 px-4 py-3 text-center">
                            <a href="{{ route('notifications.index') }}"
                               class="text-sm font-semibold text-blue-700 hover:text-blue-900 hover:underline">
                                View all messages
                            </a>
                        </div>
                    </div>
                </details>

                <span class="hidden text-sm text-slate-500 lg:inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Sign out
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}"
                   class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Sign in
                </a>
                <a href="{{ route('register') }}"
                   class="rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                    Create account
                </a>
            @endauth
        </div>
    </div>
</nav>
