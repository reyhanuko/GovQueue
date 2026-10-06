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
        ]);
    } catch (\Throwable) {
        // No usable session; simply show no flash message.
        $flash = [];
    }

    $validationErrors = isset($errors) && $errors->any() ? $errors->all() : [];
@endphp

@if (! empty($flash['success']))
    <div role="status" x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show"
         x-transition.opacity
         class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
        {{ $flash['success'] }}
    </div>
@endif

@if (! empty($flash['status']))
    {{-- Some controllers (e.g. ForgotPasswordController) use the Laravel
         convention: session('status'). Render it so the action never looks
         like it did nothing. --}}
    <div role="status" x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show"
         x-transition.opacity
         class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
        {{ $flash['status'] }}
    </div>
@endif

@if (! empty($flash['error']))
    <div role="alert" x-data="{ show: true }"
         x-init="setTimeout(() => show = false, 12000)" x-show="show" x-transition.opacity
         class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
        {{ $flash['error'] }}

        {{-- Blocked administrator actions carry a hint explaining the way out.
             Without it the message just says "no", which is useless at 5pm. --}}
        @if (! empty($flash['hint']))
            <p class="mt-1.5 font-normal text-rose-700">{{ $flash['hint'] }}</p>
        @endif
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
