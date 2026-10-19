@props([
    'blocker',
    'id',
])

{{--
    A Delete button that cannot be pressed, and the reason why.

    The reason used to be printed under the button, in a 15rem column, and it
    made every row of the table four hundred pixels tall. A list of ten
    services came out as a column of mostly white space, with the explanation
    pushed off the right-hand edge of a table that already scrolls sideways -
    so the text that was there to explain the disabled button was the one thing
    nobody could see.

    It is one click away now, in a disclosure, which is the same pattern the
    audit trail uses for its raw payload. The button stays disabled: the
    action really is refused, and a button that looks pressable and is not is
    worse than one that plainly is not.

    The reason is still in the accessibility tree, and the disclosure is
    keyboard operable, so the explanation is not something a person has to be
    able to see in order to find.
--}}
<div class="text-right">
    <button type="button" disabled
            class="cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600"
            aria-describedby="delete-blocked-{{ $id }}">
        Delete
    </button>

    <details class="mt-1 text-right">
        <summary class="cursor-pointer text-xs font-medium text-blue-700 hover:underline">
            Why not?
        </summary>

        <p id="delete-blocked-{{ $id }}"
           class="mt-1 max-w-[15rem] text-xs text-slate-700">
            {{ $blocker->getMessage() }}
        </p>

        @if ($blocker->hint)
            <p class="mt-0.5 max-w-[15rem] text-xs font-medium text-blue-800">
                {{ $blocker->hint }}
            </p>
        @endif
    </details>
</div>
