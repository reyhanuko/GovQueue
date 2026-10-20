{{--
    The window chips.

    route(), not request()->fullUrlWithQuery(): the board re-renders on every
    poll, and a poll is handled by the Livewire update endpoint - so
    fullUrlWithQuery() built the link from the endpoint's own URL and produced
    /livewire-42034e5b/update?w=WINDOW_1. Clicking it asked a POST-only route
    for a GET, which is a 405, and the error page it produced had no session -
    so the navigation on it showed a signed-in visitor as a guest.

    The window being shown is marked, so somebody who has pinned one knows
    which - and the rest are still one click away, rather than a trip back to
    the whole board first.
--}}
<div class="mt-8 flex flex-wrap items-center justify-center gap-2">
    <span class="text-xs uppercase tracking-widest text-slate-500">
        {{ $label }}
    </span>

    @foreach ($windows as $window)
        <a href="{{ route('display.index', ['w' => $window->code]) }}"
           @if ($window->code === $currentWindow?->code) aria-current="true" @endif
           class="rounded-full px-3 py-1 text-xs {{ $window->code === $currentWindow?->code
                ? 'bg-blue-100 font-semibold text-blue-900'
                : 'bg-slate-100 text-slate-700 hover:bg-slate-200 hover:text-slate-900' }}">
            {{ $window->name }}
        </a>
    @endforeach
</div>
