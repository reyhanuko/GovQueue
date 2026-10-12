@extends('layouts.app')

@section('title', $window->exists ? 'Edit Window' : 'Add Window')

@section('content')
    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('admin.windows.index') }}" class="text-blue-700 hover:underline">Service windows</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">{{ $window->exists ? 'Edit' : 'New' }}</span>
    </nav>

    <h1 class="mt-4 text-2xl font-bold text-slate-900">
        {{ $window->exists ? 'Edit '.$window->name : 'Add a service window' }}
    </h1>

    <form method="POST"
          action="{{ $window->exists ? route('admin.windows.update', $window) : route('admin.windows.store') }}"
          class="mt-6 max-w-2xl space-y-5">
        @csrf
        @if ($window->exists)
            @method('PUT')
        @endif

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <x-admin.field name="name" label="Window name" required
                          :value="$window->name"
                          placeholder="Window 1 - Certificates" />

            <div class="mt-5">
                <x-admin.field name="code" label="Code" required
                              :value="$window->code"
                              placeholder="WINDOW_1"
                              help="Used in the public URL and on the lobby display. Capital letters, numbers, and underscores only." />
            </div>

            <div class="mt-5">
                <x-admin.field name="description" type="textarea" label="Description"
                              :value="$window->description"
                              placeholder="Optional. Shown to staff on the console." />
            </div>

            @php
                $serviceDurations = App\Models\Service::where('service_window_id', $window->id ?? 0)
                    ->where('is_active', true)->where('duration_minutes', '>', 0)->pluck('duration_minutes');
                $derivedAverage = $serviceDurations->isEmpty() ? null : round((float) $serviceDurations->avg(), 1);
                $configuredAverage = (float) old('avg_service_minutes', $window->avg_service_minutes ?? 0);
                $disagrees = $derivedAverage !== null
                    && ($configuredAverage < $derivedAverage - 1 || $configuredAverage > $derivedAverage + 1);
            @endphp

            <div class="mt-5">
                <x-admin.field name="avg_service_minutes" type="number" label="Average service time (minutes)" required
                              :value="$window->avg_service_minutes ?? 15" min="1" max="240" step="1"
                              help="Used to estimate how long a client will wait. This is an estimate, not a promise." />

                @if ($disagrees)
                    <p role="alert" class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <span class="font-semibold">This does not match the window's services.</span>
                        Active services here take {{ $derivedAverage }} minutes on average, but this is set to
                        {{ $configuredAverage }}. Until there is enough completed history to measure, estimates for
                        this window are taken from the services ({{ $derivedAverage }} min), not from this number.
                        Set it to {{ ceil($derivedAverage) }} if the difference is not intended.
                    </p>
                @elseif ($derivedAverage !== null)
                    <p class="mt-2 text-xs text-slate-500">
                        Matches the average duration of this window's services ({{ $derivedAverage }} min).
                    </p>
                @endif
            </div>

            <div class="mt-5">
                <label class="flex items-start gap-3 text-sm text-slate-800">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $window->exists ? $window->is_active : true))
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                    <span>
                        <span class="font-medium">Active</span>
                        <span class="block text-xs text-slate-500">
                            Inactive windows are hidden from clients but keep all their history.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                {{ $window->exists ? 'Save changes' : 'Create window' }}
            </button>
            <a href="{{ route('admin.windows.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Cancel
            </a>
        </div>
    </form>
@endsection
