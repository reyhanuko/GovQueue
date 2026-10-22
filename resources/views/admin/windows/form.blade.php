@extends('layouts.app')

@section('title', $window->exists ? 'Edit Window' : 'Add Window')

@section('content')
    <nav class="flex items-center gap-1.5 text-sm" aria-label="Breadcrumb">
        <a href="{{ route('admin.windows.index') }}" class="text-slate-500 hover:text-slate-700">Service windows</a>
        <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
        <span class="font-medium text-slate-800">{{ $window->exists ? 'Edit' : 'New' }}</span>
    </nav>

    <h1 class="mt-4 text-2xl font-bold text-slate-900">
        {{ $window->exists ? 'Edit '.$window->name : 'Add a service window' }}
    </h1>

    <form id="window-form" method="POST"
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
                {{--
                    The service form has always warned that changing the code
                    breaks links already shared with clients. This one only
                    described what the code is used for, which reads as
                    permission to change it freely - and it appears in public
                    URLs and on the lobby display, so a change here strands any
                    link a client was given.

                    Locked rather than warned once the window exists and has been
                    used: the code is an identifier that has appeared in public
                    and on printed material, so renaming it is a one-way door
                    that offers no benefit. A window with nothing attached keeps
                    an editable field, because nothing depends on it yet.
                --}}
                <x-admin.field name="code" label="Code" required
                              :value="$window->code"
                              :disabled="$codeIsLocked"
                              placeholder="WINDOW_1"
                              help="Capital letters, numbers, and underscores only." />

                {{-- A disabled input is not submitted, so saving an otherwise
                     unchanged window would otherwise fail validation on a code
                     the administrator never touched. --}}
                @if ($codeIsLocked)
                    <input type="hidden" name="code" value="{{ $window->code }}">
                @endif

                @if ($codeIsLocked)
                    <p class="mt-1.5 text-xs text-slate-700">
                        <strong class="font-semibold text-slate-900">This code is now locked.</strong>
                        It appears in public URLs and on the lobby display, and it is also what
                        queue numbers are prefixed with, so changing it would break links already
                        shared with clients and any lobby screen that names it.
                        @if ($window->queue_prefix)
                            It currently issues numbers beginning <span class="font-mono font-semibold">{{ $window->queue_prefix }}-</span>.
                        @endif
                    </p>
                @else
                    <p class="mt-1.5 text-xs text-slate-600">
                        Used in the public URL and on the lobby display. You can change it until the
                        window has services, staff or generated slots attached.
                    </p>
                @endif
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
            <button id="window-submit" type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition-opacity hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-40">
                {{ $window->exists ? 'Save changes' : 'Create window' }}
            </button>
            <a href="{{ route('admin.windows.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Cancel
            </a>
        </div>
    </form>
@endsection

@if ($window->exists)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form    = document.getElementById('window-form');
    const saveBtn = document.getElementById('window-submit');
    if (!form || !saveBtn) return;

    function snapshot(f) {
        const parts = [];
        for (const el of f.elements) {
            if (!el.name || el.disabled) continue;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
                parts.push(el.name + '=');
            } else if (el.type !== 'submit' && el.type !== 'button') {
                parts.push(el.name + '=' + el.value);
            }
        }
        return parts.join('&');
    }

    const initial = snapshot(form);

    function update() {
        const dirty = snapshot(form) !== initial;
        saveBtn.disabled = !dirty;
        saveBtn.title = dirty ? '' : 'No changes to save yet';
    }

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
});
</script>
@endpush
@endif
