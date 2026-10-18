{{-- Lobby display. Queue numbers only: no client names, no services, no
     priority claims. That is what makes it safe to leave unauthenticated. --}}
<div
    class="min-h-screen bg-slate-950 text-slate-100"
    {{-- 10s poll: the fallback that keeps the board correct when Reverb is
         down. Websockets handle the normal case; this is what makes a dead
         socket a slower board rather than a frozen one. --}}
    wire:poll.10s
    x-data="govQueueDisplay()"
    x-init="init()"
    data-window-code="{{ $windowCode }}"
    {{-- Every window this board is listening to, so the all-window view stays
         on websockets rather than quietly dropping to the 10s poll. --}}
    data-window-codes="{{ implode(',', $this->subscribedCodes) }}"
>
    <header class="border-b border-slate-800 px-6 py-5">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black tracking-tight">
                    {{ $this->showsAllWindows ? 'Now Serving' : ($this->window?->name ?? 'Queue Display') }}
                </h1>
                <p class="text-sm text-slate-400">
                    {{ config('govqueue.office.lgu') }} &middot;
                    {{ \Illuminate\Support\Carbon::parse($queueDate)->format('l, M d Y') }}
                </p>
            </div>

            {{-- Connection state is driven by the Alpine state in govqueue.js:
                 'live' when the socket is up, 'reconnecting' while Pusher is
                 retrying, 'offline' once the socket has failed and the poll
                 below is the only thing keeping these numbers correct. --}}
            <div class="flex items-center gap-3">
                {{-- A way back. This board is opened full-screen on a lobby TV
                     or a tablet, with no browser chrome and no way to guess the
                     URL of anything else - a kiosk that can only display one
                     page and cannot be navigated is a screen nobody can get out
                     of without finding a keyboard. Hidden from the TV case only
                     by being small and unobtrusive, not by being absent. --}}
                <a href="{{ route('home') }}"
                   class="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300 hover:bg-slate-700 hover:text-white">
                    &larr; Back to GovQueue
                    <span class="sr-only"> &mdash; leave the queue display</span>
                </a>

                <span class="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300">
                    Updated <span x-text="lastUpdatedLabel">{{ $lastUpdatedAt }}</span>
                </span>
                <span class="flex items-center gap-2 rounded-full bg-slate-800 px-3 py-1 text-xs"
                      role="status" aria-live="polite">
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="dotClasses()"
                        aria-hidden="true"
                    ></span>
                    <span x-text="label()">Connecting</span>
                </span>
            </div>
        </div>
    </header>

    {{-- Prominent offline warning banner --}}
    <div x-show="state !== 'live'"
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform -translate-y-2"
         x-transition:enter-end="opacity-100 transform translate-y-0"
         role="alert"
         class="border-b-4 px-6 py-4 text-white"
         :class="state === 'offline' ? 'border-rose-600 bg-rose-600' : 'border-amber-600 bg-amber-500'">
        <div class="mx-auto max-w-6xl">
            <div class="flex items-start gap-4">
                <svg class="h-6 w-6 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <div class="flex-1">
                    <p class="text-lg font-bold"
                       x-text="state === 'offline' ? '⚠️ Offline — updates every 10 seconds' : '⚠️ Connection interrupted'">
                        ⚠️ Connection interrupted
                    </p>
                    <p class="mt-1 text-sm">
                        <span x-show="state === 'offline'">
                            Live updates are unavailable. This board is refreshing on a timer, so the numbers
                            below are still current.
                        </span>
                        <span x-show="state !== 'offline'" x-cloak>
                            The display may be outdated until the connection is restored.
                        </span>
                        Last updated: <span x-text="lastUpdatedLabel">{{ $lastUpdatedAt }}</span>
                    </p>
                    <p class="mt-1 text-xs opacity-90">
                        The display will update automatically when the connection is restored.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <main class="mx-auto max-w-6xl px-6 py-8">
        {{--
            Every window at once, by default.

            This board used to show a single window, chosen by defaulting to the
            first active one, with no indication that any other existed. A client
            waiting for Window 2 had no way to know where to look.

            Numbers only, as before. Nothing below loads a client name, a service
            or a priority claim - it is an unauthenticated screen in a lobby, and
            that is what keeps it safe to leave running.
        --}}
        @if ($this->showsAllWindows)
            @if ($this->isClosed)
                <div class="rounded-2xl border border-amber-500/40 bg-amber-500/10 p-12 text-center">
                    <p class="text-3xl font-bold text-amber-300">The office is closed today</p>
                    <p class="mt-2 text-lg text-amber-200/80">Please come back on a working day.</p>
                </div>
            @elseif ($this->windows->isEmpty())
                <div class="rounded-2xl border border-rose-500/40 bg-rose-500/10 p-12 text-center">
                    <p class="text-2xl font-bold text-rose-300">No service windows are set up</p>
                    <p class="mt-2 text-rose-200/80">
                        Please ask staff to check the display setup.
                    </p>
                </div>
            @else
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->windowRows as $row)
                        @php $window = $row['window']; @endphp

                        <section class="flex flex-col rounded-2xl border border-slate-800 bg-slate-900/60"
                                 aria-labelledby="window-{{ $window->code }}">
                            <h2 id="window-{{ $window->code }}"
                                class="border-b border-slate-800 px-5 py-3 text-lg font-bold">
                                {{ $window->name }}
                            </h2>

                            {{-- Now serving, large: this is the number people are
                                 watching for from across a lobby. --}}
                            <div class="px-5 pt-5">
                                <p class="text-xs font-bold uppercase tracking-widest text-slate-400">
                                    Now serving
                                </p>

                                @if ($row['serving'])
                                    <p class="mt-2 rounded-xl bg-emerald-500 py-6 text-center text-6xl font-black tracking-tight text-slate-950">
                                        {{ $row['serving']->queue_number }}
                                    </p>
                                    <p class="mt-2 text-center text-sm font-semibold text-emerald-300">
                                        Please proceed to {{ $window->name }}
                                    </p>
                                @else
                                    <p class="mt-2 rounded-xl border border-slate-800 bg-slate-950 py-6 text-center text-4xl font-black text-slate-700">
                                        ---
                                    </p>
                                    <p class="mt-2 text-center text-sm text-slate-400">
                                        No client is being served here
                                    </p>
                                @endif
                            </div>

                            <div class="px-5 pb-5 pt-4">
                                <p class="text-xs font-bold uppercase tracking-widest text-slate-400">
                                    Next in line
                                </p>

                                @if ($row['upNext']->isEmpty())
                                    <p class="mt-2 text-sm text-slate-400">
                                        Nobody is waiting here.
                                    </p>
                                @else
                                    <ol class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($row['upNext'] as $index => $appointment)
                                            <li @class([
                                                'rounded-lg px-3 py-2 text-center text-2xl font-black tracking-tight',
                                                'bg-sky-500/20 text-sky-200 ring-1 ring-sky-500/60' => $index === 0,
                                                'bg-slate-950 text-slate-300' => $index !== 0,
                                            ])>
                                                {{ $appointment->queue_number }}
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        </section>
                    @endforeach
                </div>

                {{-- The statutory basis, on the all-window board too. It was only printed in
                     the single-window view, so making that view optional would
                     otherwise have dropped it entirely. --}}
                <p class="mt-10 text-center text-xs text-slate-500">
                    Priority clients are called in line with RA 9994 (senior citizens) and
                    RA 10754 (persons with disability) once staff have verified the claim at
                    the counter; pregnant clients are served under the office's own priority policy.
                    Estimated waiting times are shown in your own GovQueue account.
                </p>

                {{-- A lobby running several screens can pin one window. "?w=" is the
                     existing parameter name, kept rather than replaced. --}}
                <div class="mt-8 flex flex-wrap items-center justify-center gap-2">
                    <span class="text-xs uppercase tracking-widest text-slate-500">
                        Show one window only
                    </span>
                    @foreach ($this->windows as $window)
                        <a href="{{ request()->fullUrlWithQuery(['w' => $window->code]) }}"
                           class="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300 hover:bg-slate-700 hover:text-white">
                            {{ $window->name }}
                        </a>
                    @endforeach
                </div>
            @endif
        @elseif (! $this->window)
            <div class="rounded-2xl border border-rose-500/40 bg-rose-500/10 p-12 text-center">
                <p class="text-2xl font-bold text-rose-300">This service window is not available</p>
                <p class="mt-2 text-rose-200/80">The window may be misconfigured or inactive. Please ask staff to check the display setup.</p>
            </div>
        @elseif ($this->isClosed)
            <div class="rounded-2xl border border-amber-500/40 bg-amber-500/10 p-12 text-center">
                <p class="text-2xl font-bold text-amber-300">The office is closed today</p>
                <p class="mt-2 text-amber-200/80">Please come back on a working day.</p>
            </div>
        @else
            {{-- Now serving --}}
            <section aria-labelledby="now-serving" class="mb-10">
                <h2 id="now-serving" class="text-sm font-bold uppercase tracking-widest text-slate-400">
                    Now serving
                </h2>

                @if ($this->serving)
                    <div class="mt-3 rounded-2xl bg-emerald-500 px-8 py-10 text-center text-slate-950">
                        <p class="text-7xl font-black tracking-tight sm:text-8xl">
                            {{ $this->serving->queue_number }}
                        </p>
                        <p class="mt-3 text-lg font-semibold">Please proceed to the window</p>
                    </div>
                @else
                    <div class="mt-3 rounded-2xl border border-slate-800 bg-slate-900 px-8 py-10 text-center">
                        <p class="text-5xl font-black text-slate-700">---</p>
                        <p class="mt-3 text-slate-400">No client is being served right now</p>
                    </div>
                @endif
            </section>

            {{-- Next in line --}}
            <section aria-labelledby="up-next">
                <h2 id="up-next" class="text-sm font-bold uppercase tracking-widest text-slate-400">
                    Next in line
                </h2>

                @php $upNext = $this->upNext; @endphp

                @if ($upNext->isEmpty())
                    <p class="mt-3 rounded-2xl border border-slate-800 bg-slate-900 px-6 py-8 text-center text-slate-400">
                        Nobody is waiting. Your number will appear here.
                    </p>
                @else
                    <ol class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($upNext as $index => $appointment)
                            <li @class([
                                'rounded-2xl border px-5 py-5 text-center',
                                'border-sky-500/60 bg-sky-500/10' => $index === 0,
                                'border-slate-800 bg-slate-900' => $index !== 0,
                            ])>
                                <p class="text-4xl font-black tracking-tight">
                                    {{ $appointment->queue_number }}
                                </p>
                                <p class="mt-1 text-xs uppercase tracking-wider text-slate-400">
                                    {{ $index === 0 ? 'Next' : '#'.($index + 1) }}
                                </p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <p class="mt-10 text-center text-xs text-slate-500">
                Priority clients are called in line with RA 9994 (senior citizens) and
                RA 10754 (persons with disability) once staff have verified the claim at
                the counter; pregnant clients are served under the office's own priority policy.
                Estimated waiting times are shown in your own GovQueue account.
            </p>
        @endif
    </main>
</div>