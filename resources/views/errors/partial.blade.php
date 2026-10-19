{{--
    Shared error page body.

    Every status page is this plus three words, so they cannot drift apart in
    layout, wording register, or the reference code.

    The reference code is the request id from CorrelateRequests. It is the one
    thing on this page a person can usefully read out: it is echoed as a
    response header, carried into every log line for the request, and it lets
    staff find the failure without a timestamp and a guess. Without it, "it
    broke" is the only thing a resident or a clerk can report.

    Plain language only. No class names, no SQL, no stack frames - the debug
    page may still appear locally when APP_DEBUG is on, but nothing on this
    page ever renders one.
--}}
@php
    $referenceCode = request()->attributes->get(\App\Http\Middleware\CorrelateRequests::ATTRIBUTE);
@endphp

<div class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-10 text-center">
    <p class="text-sm font-semibold uppercase tracking-wider text-rose-600">{{ $code }}</p>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">{{ $title }}</h1>
    <p class="mt-3 text-sm text-slate-600">{{ $message }}</p>

    @if ($referenceCode)
        <p class="mt-5 text-xs text-slate-500">
            If you need to report this, quote this reference:
            <span class="font-mono font-semibold text-slate-700">{{ $referenceCode }}</span>
        </p>
    @endif

    {{-- Named after the screen the visitor actually has. A clerk has a staff
         console and a client has their appointments; only an administrator has
         a dashboard, and a visitor with no account has the home page. --}}
    <x-user-home-link
        class="mt-6 inline-block rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800" />
</div>