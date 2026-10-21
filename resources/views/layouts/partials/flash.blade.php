{{--
    Flash and validation messages.

    This partial is included by the site layout, which means it also renders on
    error pages - the 403, the 404, and the "temporarily unavailable" page shown
    when the database is down. Two things can be true here that are true nowhere
    else:

      - $errors may be undefined. Laravel only shares it into the view via
        middleware, and an error rendered by the exception handler can happen
        before that middleware has run.

      - the session may be unreadable. GovQueue stores sessions in MySQL, so
        when the database is unreachable - precisely when this page is being
        rendered - reading the session throws as well.

    Either failure replaced a readable message with a blank 500 and hid the real
    cause, so both are handled explicitly rather than assumed to work.

    ONE message, ONE place, by kind:

      success  Toast. It is a confirmation, not information, and the person who
               just acted already knows it worked - they pressed the button. A
               toast works from anywhere on the page, which is the reason it is
               a toast: an administrator deleting from the bottom of a table
               hundreds of rows long is returned to the top, and an inline
               banner would be above the fold and never seen.

      error    Inline. A refusal is information the person has to act on, and a
               12-second timer expires before somebody who is being interrupted
               has finished reading it. Inline also puts it in the reading order
               rather than in a corner they may not be looking at.

      warning  Inline, and it never dismisses. Same reasoning, stronger.

    Why this was changed: every action used to render the success message
    twice, once as a toast and once inline. Visually that was only clutter, but
    both carried role="status", so a screen reader announced the same sentence
    twice - on every successful action, permanently. The justification in the
    old comment was that the inline copy covered a case where the overlay failed
    to render. That case does not exist: x-show hides the toast by adding a
    style, so with no JavaScript the toast renders normally and simply never
    dismisses. The duplicate was protecting against nothing.
--}}
@php
    $flash = [];

    try {
        $flash = array_filter([
            'success' => session('success'),
            'error' => session('error'),
            'hint' => session('hint'),
            'status' => session('status'),
            // A warning accompanies a successful action: the thing was saved,
            // but the administrator should know something about it.
            'warning' => session('warning'),
        ]);
    } catch (\Throwable) {
        // No usable session; simply show no flash message.
        $flash = [];
    }

    $validationErrors = isset($errors) && $errors->any() ? $errors->all() : [];
    $hasToast = ! empty($flash['success']) || ! empty($flash['status']);
@endphp

{{-- Confirmations only. Fixed overlay so the page does not shift and so the
     message is still visible however far down the page the action happened. --}}
@if ($hasToast)
    <div class="pointer-events-none fixed right-4 top-4 z-50 flex w-full max-w-sm flex-col gap-2 sm:right-6 sm:top-6">
        @foreach (['success', 'status'] as $kind)
            @if (! empty($flash[$kind]))
                {{-- Closeable, because 8 seconds is short for somebody who is
                     still reading it, and because a message you cannot dismiss
                     early is a message that interrupts. --}}
                <div role="status" x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show"
                     x-transition.opacity
                     class="pointer-events-auto flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-lg">
                    <span class="flex-1">{{ $flash[$kind] }}</span>
                    <button type="button" x-on:click="show = false" x-show="show"
                            aria-label="Dismiss this message"
                            class="-mr-1 shrink-0 rounded p-1 text-emerald-600 hover:bg-emerald-100 hover:text-emerald-900 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            @endif
        @endforeach
    </div>
@endif

{{-- Refusals. Inline, persistent, and never auto-dismissing: something the
     person has to act on must not expire before they have read it. --}}
@if (! empty($flash['error']))
    <div role="alert" class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
        {{ $flash['error'] }}

        {{-- Blocked administrator actions carry a hint explaining the way out.
             Without it the message just says "no", which is useless at 5pm. --}}
        @if (! empty($flash['hint']))
            <p class="mt-1.5 font-normal text-rose-700">{{ $flash['hint'] }}</p>
        @endif
    </div>
@endif

{{-- A hint that arrived without an error. On its own it is just information -
     "everything you asked for was already there, nothing changed" - and
     rendering nothing there made a successful action look like it had silently
     done nothing. --}}
@if (! empty($flash['hint']) && empty($flash['error']))
    <div role="status" class="mb-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-medium text-blue-900">
        {{ $flash['hint'] }}
    </div>
@endif

{{-- Information to act on, deliberately neither a toast nor auto-dismissing. --}}
@if (! empty($flash['warning']))
    <div role="alert" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">Heads up</p>
        <p class="mt-1">{{ $flash['warning'] }}</p>
    </div>
@endif

@if (! empty($validationErrors))
    <div role="alert" class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <p class="font-semibold">Please fix the following:</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($validationErrors as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
