{{-- Lobby display. Queue numbers only: no client names, no services, no
     priority claims. That is what makes it safe to leave unauthenticated. --}}
<div
    class="min-h-screen bg-slate-950 text-slate-100"
    wire:poll.30s
    x-data="govQueueDisplay()"
    x-init="init()"
    data-window-code="{{ $windowCode }}"
>
    <header class="border-b border-slate-800 px-6 py-5">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black tracking-tight">
                    {{ $this->window?->name ?? 'Queue Display' }}
                </h1>
                <p class="text-sm text-slate-400">
                    {{ config('govqueue.office.lgu') }} &middot;
                    {{ \Illuminate\Support\Carbon::parse($queueDate)->format('l, M d Y') }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <span class="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300">
                    Updated {{ $lastUpdatedAt }}
                </span>
                <span class="flex items-center gap-2 rounded-full bg-slate-800 px-3 py-1 text-xs">
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="connected ? 'bg-emerald-400' : 'bg-amber-400'"
                        aria-hidden="true"
                    ></span>
                    <span x-text="connected ? 'Live' : 'Reconnecting'">Live</span>
                </span>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-6 py-8">
        @if ($this->isClosed)
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
                Priority clients are called in line with RA 9994 and RA 10754 once verified at the counter.
                Estimated waiting times are shown in your own GovQueue account.
            </p>
        @endif
    </main>
</div>