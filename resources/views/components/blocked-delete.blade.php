@props([
    'blocker',
    'id',
])

{{--
    A Delete button that cannot be pressed, and the reason why.

    Previous iteration: a <details> disclosure labelled "Why not?" which
    required an extra click, expanded mid-table, and pushed neighbouring
    rows around.

    Current: the reason lives in a tooltip that appears on hover/focus
    above the disabled button. No extra click, no layout shift, and the
    explanation is always reachable by keyboard (focus the button → the
    tooltip is shown via the group-focus-within variant).
--}}
<div class="group relative inline-block text-left">
    <button type="button" disabled
            id="delete-blocked-{{ $id }}"
            class="cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-400"
            aria-describedby="delete-blocked-tip-{{ $id }}">
        Delete
    </button>

    {{-- Tooltip: visible on hover or focus-within of the wrapper div. --}}
    <div id="delete-blocked-tip-{{ $id }}"
         role="tooltip"
         class="pointer-events-none absolute bottom-full right-0 z-20 mb-2 w-56 rounded-lg border border-slate-200 bg-white p-3 shadow-lg
                opacity-0 transition-opacity duration-150
                group-hover:opacity-100 group-focus-within:opacity-100">
        <p class="text-xs text-slate-700">{{ $blocker->getMessage() }}</p>
        @if ($blocker->hint)
            <p class="mt-1 text-xs font-medium text-indigo-600">{{ $blocker->hint }}</p>
        @endif
        {{-- Small arrow pointing down toward the button --}}
        <span class="absolute -bottom-1.5 right-4 h-3 w-3 rotate-45 border-b border-r border-slate-200 bg-white"></span>
    </div>
</div>
