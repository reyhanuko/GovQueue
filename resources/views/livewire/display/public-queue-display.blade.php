{{-- Lobby display. Queue numbers only: no client names, no services, no
     priority claims. That is what makes it safe to leave unauthenticated.

     It used to be a full-bleed dark screen with no site navigation at all, on
     the grounds that it is opened full-screen on a lobby TV. Which it is - and
     it is also a page a resident reaches by clicking "Queue Display" in the
     navigation or "View live queue" on the home page, and those people got a
     black page in the middle of a white site with no way back.

     So it is a page of the site now, like every other page: the same light
     background, the same navigation, the same card and type scale. The board's
     own qualities are untouched - the number being served is still the largest
     thing on the screen, and it is still a colour block you can read from
     across a room. --}}
<div
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
    {{-- A <header> inside the page's <main>: it heads this section of the
         page, and is not a banner landmark, because the layout's own header is
         the navigation above it. --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black tracking-tight text-slate-900">
                {{ $this->showsAllWindows ? 'Now Serving' : ($this->window?->name ?? 'Queue Display') }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ config('govqueue.office.lgu') }} &middot;
                {{ \Illuminate\Support\Carbon::parse($queueDate)->format('l, M d Y') }}
            </p>
        </div>

        {{-- Connection state is driven by the Alpine state in govqueue.js:
             'live' when the socket is up, 'reconnecting' while Pusher is
             retrying, 'offline' once the socket has failed and the poll
             below is the only thing keeping these numbers correct. --}}
        <div class="flex items-center gap-2">
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">
                Updated <span x-text="lastUpdatedLabel">{{ $lastUpdatedAt }}</span>
            </span>
            <span class="flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600"
                  role="status" aria-live="polite">
                <span
                    class="h-2 w-2 rounded-full"
                    :class="dotClasses()"
                    aria-hidden="true"
                ></span>
                <span x-text="label()">Connecting</span>
            </span>
        </div>
    </header>

    {{-- Prominent offline warning banner --}}
    <div x-show="state !== 'live'"
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform -translate-y-2"
         x-transition:enter-end="opacity-100 transform translate-y-0"
         role="alert"
         class="mt-6 flex items-start gap-4 rounded-2xl border px-6 py-5"
         :class="state === 'offline'
             ? 'border-rose-200 bg-rose-50 text-rose-900'
             : 'border-amber-200 bg-amber-50 text-amber-900'">
        <svg class="h-6 w-6 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
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

    <section class="mt-8">
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
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-12 text-center">
                    <p class="text-3xl font-bold text-amber-900">The office is closed today</p>
                    <p class="mt-2 text-lg text-amber-800">Please come back on a working day.</p>
                </div>
            @elseif ($this->windows->isEmpty())
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-12 text-center">
                    <p class="text-2xl font-bold text-rose-900">No service windows are set up</p>
                    <p class="mt-2 text-rose-800">
                        Please ask staff to check the display setup.
                    </p>
                </div>
            @else
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->windowRows as $row)
                        @php $window = $row['window']; @endphp

                        <section class="flex flex-col rounded-2xl border border-slate-200 bg-white"
                                 aria-labelledby="window-{{ $window->code }}">
                            <h2 id="window-{{ $window->code }}"
                                class="border-b border-slate-200 px-5 py-3 text-lg font-bold text-slate-900">
                                {{ $window->name }}
                            </h2>

                            {{-- Now serving, large: this is the number people are
                                 watching for from across a lobby. The block is the
                                 one saturated colour on the page, so the eye goes
                                 to it and nothing else has to compete. --}}
                            <div class="px-5 pt-5">
                                <p class="text-xs font-bold uppercase tracking-widest text-slate-500">
                                    Now serving
                                </p>

                                @if ($row['serving'])
                                    <p class="mt-2 rounded-xl bg-emerald-500 py-6 text-center font-black tracking-tight tabular-nums text-slate-950 {{ $this->servingNumberClasses($row['serving']->queue_number) }}">
                                        {{ $row['serving']->queue_number }}
                                    </p>
                                    <p class="mt-2 text-center text-sm font-semibold text-emerald-800">
                                        Please proceed to {{ $window->name }}
                                    </p>
                                @else
                                    <p class="mt-2 rounded-xl border border-slate-200 bg-slate-50 py-6 text-center text-4xl font-black text-slate-400">
                                        ---
                                    </p>
                                    <p class="mt-2 text-center text-sm text-slate-500">
                                        No client is being served here
                                    </p>
                                @endif
                            </div>

                            <div class="px-5 pb-5 pt-4">
                                <p class="text-xs font-bold uppercase tracking-widest text-slate-500">
                                    Next in line
                                </p>

                                @if ($row['upNext']->isEmpty())
                                    <p class="mt-2 text-sm text-slate-500">
                                        Nobody is waiting here.
                                    </p>
                                @else
                                    <ol class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($row['upNext'] as $index => $appointment)
                                            <li @class([
                                                'rounded-lg px-3 py-2 text-center font-black tracking-tight tabular-nums',
                                                'bg-sky-50 text-sky-800 ring-1 ring-sky-200' => $index === 0,
                                                'bg-slate-100 text-slate-700' => $index !== 0,
                                                $this->upNextNumberClasses($appointment->queue_number),
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
                           class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700 hover:bg-slate-200 hover:text-slate-900">
                            {{ $window->name }}
                        </a>
                    @endforeach
                </div>
            @endif
        @elseif (! $this->window)
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-12 text-center">
                <p class="text-2xl font-bold text-rose-900">This service window is not available</p>
                <p class="mt-2 text-rose-800">The window may be misconfigured or inactive. Please ask staff to check the display setup.</p>
            </div>
        @elseif ($this->isClosed)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-12 text-center">
                <p class="text-3xl font-bold text-amber-900">The office is closed today</p>
                <p class="mt-2 text-lg text-amber-800">Please come back on a working day.</p>
            </div>
        @else
            {{-- Now serving --}}
            <section aria-labelledby="now-serving" class="mb-10">
                <h2 id="now-serving" class="text-sm font-bold uppercase tracking-widest text-slate-500">
                    Now serving
                </h2>

                @if ($this->serving)
                    <div class="mt-3 rounded-2xl bg-emerald-500 px-8 py-10 text-center text-slate-950">
                        <p class="font-black tracking-tight tabular-nums {{ $this->servingNumberClasses($this->serving->queue_number) }}">
                            {{ $this->serving->queue_number }}
                        </p>
                        <p class="mt-3 text-lg font-semibold">Please proceed to the window</p>
                    </div>
                @else
                    <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-8 py-10 text-center">
                        <p class="text-5xl font-black text-slate-400">---</p>
                        <p class="mt-3 text-slate-500">No client is being served right now</p>
                    </div>
                @endif
            </section>

            {{-- Next in line --}}
            <section aria-labelledby="up-next">
                <h2 id="up-next" class="text-sm font-bold uppercase tracking-widest text-slate-500">
                    Next in line
                </h2>

                @php $upNext = $this->upNext; @endphp

                @if ($upNext->isEmpty())
                    <p class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-6 py-8 text-center text-slate-500">
                        Nobody is waiting. Your number will appear here.
                    </p>
                @else
                    <ol class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($upNext as $index => $appointment)
                            <li @class([
                                'rounded-2xl border px-5 py-5 text-center',
                                'border-sky-200 bg-sky-50' => $index === 0,
                                'border-slate-200 bg-white' => $index !== 0,
                            ])>
                                <p class="font-black tracking-tight tabular-nums {{ $this->upNextNumberClasses($appointment->queue_number) }}">
                                    {{ $appointment->queue_number }}
                                </p>
                                <p class="mt-1 text-xs uppercase tracking-wider text-slate-500">
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
            </p>
        @endif
    </section>
</div>
