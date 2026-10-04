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

        <div class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex">
            <a href="{{ route('home') }}" class="hover:text-blue-700">Home</a>
            <a href="{{ route('services.index') }}" class="hover:text-blue-700">Services</a>
            <a href="{{ route('display.index') }}" class="hover:text-blue-700">Queue Display</a>
            @auth
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="hover:text-blue-700">Admin</a>
                @elseif (auth()->user()->isStaff())
                    <a href="{{ route('staff.dashboard') }}" class="hover:text-blue-700">Staff Console</a>
                @else
                    <a href="{{ route('client.appointments.index') }}" class="hover:text-blue-700">My Appointments</a>
                @endif
            @endauth
        </div>

        <div class="flex items-center gap-2">
            @auth
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