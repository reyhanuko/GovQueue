@extends('layouts.app')

@section('title', 'Access Denied')

@section('content')
    <div class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-10 text-center">
        <p class="text-sm font-semibold uppercase tracking-wider text-rose-600">403</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">Access denied</h1>
        <p class="mt-3 text-sm text-slate-600">
            Your account does not have permission to view this page. If you believe this is a mistake,
            please contact the municipal office.
        </p>
        <a href="{{ route('dashboard') }}"
           class="mt-6 inline-block rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
            Go to my dashboard
        </a>
    </div>
@endsection