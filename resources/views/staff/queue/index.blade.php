@extends('layouts.app')

@section('title', 'Staff Console')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Staff console</h1>
        <p class="mt-1 text-sm text-slate-600">
            Signed in as {{ $staff->name }}
            @if ($staff->serviceWindow)
                &mdash; assigned to {{ $staff->serviceWindow->name }}
            @endif
        </p>
    </div>

    @livewire('staff.queue-console', ['queueDate' => $today, 'windowCode' => $initialWindow ?? ''])
@endsection