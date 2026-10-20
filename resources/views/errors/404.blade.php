@extends('layouts.app')

@section('title', 'Page Not Found')

@section('content')
    <div class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-10 text-center">
        <p class="text-sm font-semibold uppercase tracking-wider text-blue-700">Error 404</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">We could not find that page</h1>
        <p class="mt-3 text-sm text-slate-600">
            The link may be out of date, or the service code may have been retired.
            You can browse the services the LGU of Tupi currently offers.
        </p>

        {{-- Same reference code the other status pages carry, kept here because
             this page offers its own actions rather than the shared "dashboard"
             link - a 404 is often reached by someone who is not signed in. --}}
        @php($referenceCode = request()->attributes->get(\App\Http\Middleware\CorrelateRequests::ATTRIBUTE))
        @if ($referenceCode)
            <p class="mt-5 text-xs text-slate-500">
                If you need to report this, quote this reference:
                <span class="font-mono font-semibold text-slate-700">{{ $referenceCode }}</span>
            </p>
        @endif

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('services.index') }}"
               class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                Browse services
            </a>
            <a href="{{ route('home') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Go home
            </a>
        </div>
    </div>
@endsection