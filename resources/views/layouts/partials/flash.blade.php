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
@endphp

@if (! empty($flash['success']) || ! empty($flash['status']) || ! empty($flash['error']))
    {{--
        Transient toasts. Fixed overlay, stacked top-right, so the page content
        does not shift and the message stays visible however far down the
        administrator had scrolled when they pressed the button. That last part
        matters: someone deleting a row from the bottom of a long table, then
        being returned to the top of the page, would never see an inline banner.

        There is also an inline copy further down. Not redundancy for its own
        sake - `x-show` means that if the script never loads, or Alpine has not
        initialised, the toast has no styling cue at all, and the inline block is
        the one carrying the message. "Did that save?" is exactly the question
        an administrator asks twice.
    --}}
    <div class="pointer-events-none fixed right-4 top-4 z-50 flex w-full max-w-sm flex-col gap-2 sm:right-6 sm:top-6">
        @if (! empty($flash['success']))
            <div role="status" x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show"
                 x-transition.opacity
                 class="pointer-events-auto rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-lg">
                {{ $flash['success'] }}
            </div>
        @endif

        @if (! empty($flash['status']))
            {{-- Some controllers (e.g. ForgotPasswordController) use the Laravel
                 convention: session('status'). Render it so the action never looks
                 like it did nothing. --}}
            <div role="status" x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show"
                 x-transition.opacity
                 class="pointer-events-auto rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-lg">
                {{ $flash['status'] }}
            </div>
        @endif

        @if (! empty($flash['error']))
            <div role="alert" x-data="{ show: true }"
                 x-init="setTimeout(() => show = false, 12000)" x-show="show" x-transition.opacity
                 class="pointer-events-auto rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800 shadow-lg">
                {{ $flash['error'] }}

                {{-- Blocked administrator actions carry a hint explaining the way out.
                     Without it the message just says "no", which is useless at 5pm. --}}
                @if (! empty($flash['hint']))
                    <p class="mt-1.5 font-normal text-rose-700">{{ $flash['hint'] }}</p>
                @endif
            </div>
        @endif
    </div>
@endif

{{-- A hint that arrived without an error. The nested hint above is styled as
     part of a refusal ("here is the way out"); on its own it is just information
     - "everything you asked for was already there, nothing changed" - and
     rendering nothing there made a successful action look like it had silently
     done nothing. --}}
@if (! empty($flash['hint']) && empty($flash['error']))
    <div role="status" class="mb-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-medium text-blue-900">
        {{ $flash['hint'] }}
    </div>
@endif

{{--
    The same confirmations again, inline.

    This is what an administrator sees if the overlay did not render - a
    JavaScript error, a blocked asset, or a browser without <dialog>. Duplicate
    role="status" nodes announce twice, which is mildly noisy and vastly better
    than a silent save.
--}}
@if (! empty($flash['success']))
    <div role="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
        {{ $flash['success'] }}
    </div>
@endif

@if (! empty($flash['status']))
    <div role="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
        {{ $flash['status'] }}
    </div>
@endif

@if (! empty($flash['warning']))
    {{-- Deliberately not a toast and deliberately not auto-dismissing: this is
         information the administrator needs to read and act on, not a
         confirmation that has served its purpose. --}}
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
