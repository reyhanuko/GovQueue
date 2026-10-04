@extends('layouts.app')

@section('title', $title)

@section('content')
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
        <p class="text-sm font-semibold uppercase tracking-wider text-blue-700">{{ $title }}</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">Scheduled for {{ $sprint }}</h1>
        <p class="mx-auto mt-3 max-w-lg text-sm text-slate-600">
            This page is a placeholder in the Sprint 0 foundation build. The navigation shell,
            layout, and error handling are already live.
        </p>
        <a href="{{ route('home') }}" class="mt-6 inline-block rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Back to home
        </a>
    </div>
@endsection