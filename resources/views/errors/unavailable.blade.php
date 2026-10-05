@extends('layouts.app')

@section('title', 'Temporarily Unavailable')

@section('content')
    <div class="mx-auto max-w-lg rounded-2xl border border-amber-200 bg-white p-10 text-center">
        <p class="text-sm font-semibold uppercase tracking-wider text-amber-700">Service unavailable</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">We could not reach the queue system</h1>
        <p class="mt-3 text-sm text-slate-600">
            The municipal queue system is temporarily unavailable. This is almost always brief.
            Please wait a moment and try again, or proceed as a walk-in if you are already at the office.
        </p>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('home') }}"
               class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                Try again
            </a>
            <a href="{{ route('home') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Go home
            </a>
        </div>

        <p class="mt-6 text-xs text-slate-500">
            If this keeps happening, please tell staff at the information desk. No booking you have made
            has been lost.
        </p>
    </div>
@endsection