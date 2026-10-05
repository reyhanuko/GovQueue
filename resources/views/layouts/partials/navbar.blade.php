<nav class="border-b border-slate-200 bg-white" aria-label="Main navigation">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
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
        <details class="relative md:hidden">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">
                <span class="sr-only">Open main menu</span>
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M3 5.5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 5.5Zm0 4.5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 10Zm0 4.5a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H3.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                </svg>
                Menu
            </summary>

            <div class="absolute left-0 z-40 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                <ul class="space-y-0.5 text-sm font-medium text-slate-700">
                    <li><a href="{{ route('home') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Home</a></li>
                    <li><a href="{{ route('services.index') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Services</a></li>
                    <li><a href="{{ route('display.index') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Queue Display</a></li>

                    @auth
                        @if (auth()->user()->isAdmin())
                            <li><a href="{{ route('admin.dashboard') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Administration</a></li>
                            {{-- Administrators may open any window's console, so the link
                                 belongs here too, not only under the staff branch. --}}
                            <li><a href="{{ route('staff.dashboard') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Staff Console</a></li>
                        @elseif (auth()->user()->isStaff())
                            <li><a href="{{ route('staff.dashboard') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Staff Console</a></li>
                        @else
                            <li><a href="{{ route('client.appointments.index') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">My Appointments</a></li>
                            <li><a href="{{ route('client.profile.edit') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Profile</a></li>
                        @endif
                    @endauth
                </ul>
            </div>
        </details>

        <div class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex">
            <a href="{{ route('home') }}" class="hover:text-blue-700">Home</a>
            <a href="{{ route('services.index') }}" class="hover:text-blue-700">Services</a>
            <a href="{{ route('display.index') }}" class="hover:text-blue-700">Queue Display</a>
            @auth
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="hover:text-blue-700">Admin</a>
                    {{-- An administrator is allowed to open any window's console, but
                         had no link to it because the staff branch was an elseif. --}}
                    <a href="{{ route('staff.dashboard') }}" class="hover:text-blue-700">Staff Console</a>
                @elseif (auth()->user()->isStaff())
                    <a href="{{ route('staff.dashboard') }}" class="hover:text-blue-700">Staff Console</a>
                @else
                    <a href="{{ route('client.appointments.index') }}" class="hover:text-blue-700">My Appointments</a>
                    <a href="{{ route('client.profile.edit') }}" class="hover:text-blue-700">Profile</a>
                @endif
            @endauth
        </div>

        <div class="flex items-center gap-2">
            @auth
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