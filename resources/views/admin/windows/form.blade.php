@extends('layouts.app')

@section('title', $window->exists ? 'Edit Window' : 'Add Window')

@section('content')
    <nav aria-label="Breadcrumb" class="mb-4">
        <ol class="flex flex-wrap items-center gap-x-1 gap-y-1 text-sm text-slate-500">
            <li>
                <a href="{{ route('admin.dashboard') }}"
                   class="hover:text-slate-700 hover:underline">Dashboard</a>
            </li>
            <li aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 text-slate-400">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                </svg>
            </li>
            <li>
                <a href="{{ route('admin.windows.index') }}"
                   class="hover:text-slate-700 hover:underline">Service Windows</a>
            </li>
            <li aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 text-slate-400">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                </svg>
            </li>
            <li class="font-medium text-slate-900">{{ $window->exists ? 'Edit' : 'New' }}</li>
        </ol>
    </nav>

    <h1 class="text-2xl font-bold text-slate-900">
        {{ $window->exists ? 'Edit '.$window->name : 'Add a service window' }}
    </h1>

    <form id="window-form" method="POST"
          action="{{ $window->exists ? route('admin.windows.update', $window) : route('admin.windows.store') }}"
          class="mt-6 max-w-2xl space-y-5">
        @csrf
        @if ($window->exists)
            @method('PUT')
        @endif

        {{-- ── Card 1: Identity ──────────────────────────────────────────────── --}}
        <section aria-labelledby="identity-heading">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="identity-heading"
                        class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="h-3.5 w-3.5">
                            <path fill-rule="evenodd" d="M1.5 1.5A.75.75 0 0 1 2.25.75h7.5a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-.75.75H9v6a.75.75 0 0 1-.75.75h-4.5A.75.75 0 0 1 3 10.5v-6h-.75a.75.75 0 0 1-.75-.75V1.5Zm6 3H4.5v5.25h3V4.5Z" clip-rule="evenodd"/>
                        </svg>
                        Identity
                    </h2>
                </div>
                <div class="space-y-5 p-5">
                    <x-admin.field name="name" label="Window name" required
                                  :value="$window->name"
                                  placeholder="Window 1 - Certificates" />

                    <div>
                        {{--
                            Locked rather than warned once the window has been used:
                            the code appears in public URLs and on the lobby display, so
                            renaming it strands every link already shared with clients.
                        --}}
                        <x-admin.field name="code" label="Code" required
                                      :value="$window->code"
                                      :disabled="$codeIsLocked"
                                      placeholder="WINDOW_1"
                                      help="Capital letters, numbers, and underscores only." />

                        @if ($codeIsLocked)
                            <input type="hidden" name="code" value="{{ $window->code }}">
                            <div class="mt-2 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                     class="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-amber-600">
                                    <path fill-rule="evenodd" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V7A1.5 1.5 0 0 0 3 8.5v5A1.5 1.5 0 0 0 4.5 15h7a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 11 7V4.5A3.5 3.5 0 0 0 8 1Zm2 6V4.5a2 2 0 1 0-4 0V7h4Z" clip-rule="evenodd"/>
                                </svg>
                                <p class="text-xs text-amber-800">
                                    <strong class="font-semibold text-amber-900">This code is now locked.</strong>
                                    It appears in public URLs, on the lobby display, and prefixes all queue numbers
                                    (currently <span class="font-mono font-semibold">{{ $window->queue_prefix }}-</span>).
                                    Changing it would break existing links and lobby screens.
                                </p>
                            </div>
                        @else
                            <p class="mt-1.5 text-xs text-slate-500">
                                Used in the public URL and on the lobby display. You can change it until the
                                window has services, staff, or generated slots attached.
                            </p>
                        @endif
                    </div>

                    <x-admin.field name="description" type="textarea" label="Description"
                                  :value="$window->description"
                                  placeholder="Optional. Shown to staff on the console." />
                </div>
            </div>
        </section>

        {{-- ── Card 2: Configuration ─────────────────────────────────────────── --}}
        <section aria-labelledby="config-heading">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="config-heading"
                        class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="h-3.5 w-3.5">
                            <path fill-rule="evenodd" d="M6.955 1.45A.5.5 0 0 1 7.452 1h1.096a.5.5 0 0 1 .497.45l.17 1.699c.484.19.94.443 1.355.745l1.58-.668a.5.5 0 0 1 .611.169l.548.823a.5.5 0 0 1-.088.648l-1.257 1.098c.052.286.08.580.08.879 0 .3-.028.593-.08.878l1.257 1.098a.5.5 0 0 1 .088.648l-.548.824a.5.5 0 0 1-.611.168l-1.58-.668c-.414.302-.87.555-1.354.745l-.17 1.699A.5.5 0 0 1 8.548 15H7.452a.5.5 0 0 1-.497-.45l-.17-1.699a4.973 4.973 0 0 1-1.355-.745l-1.58.668a.5.5 0 0 1-.611-.168l-.548-.824a.5.5 0 0 1 .088-.648l1.257-1.098A5.002 5.002 0 0 1 4 8c0-.3.028-.593.08-.879L2.823 6.023a.5.5 0 0 1-.088-.648l.548-.823a.5.5 0 0 1 .611-.169l1.58.668c.414-.302.871-.555 1.355-.745l.17-1.699ZM6.5 8a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z" clip-rule="evenodd"/>
                        </svg>
                        Configuration
                    </h2>
                </div>
                <div class="space-y-5 p-5">
                    @php
                        $serviceDurations = App\Models\Service::where('service_window_id', $window->id ?? 0)
                            ->where('is_active', true)->where('duration_minutes', '>', 0)->pluck('duration_minutes');
                        $derivedAverage   = $serviceDurations->isEmpty() ? null : round((float) $serviceDurations->avg(), 1);
                        $configuredAverage = (float) old('avg_service_minutes', $window->avg_service_minutes ?? 0);
                        $disagrees = $derivedAverage !== null
                            && ($configuredAverage < $derivedAverage - 1 || $configuredAverage > $derivedAverage + 1);
                    @endphp

                    <div>
                        <x-admin.field name="avg_service_minutes" type="number"
                                      label="Average service time (minutes)" required
                                      :value="$window->avg_service_minutes ?? 15"
                                      min="1" max="240" step="1"
                                      help="Used to estimate how long a client will wait. This is an estimate, not a promise." />

                        @if ($disagrees)
                            <div role="alert" class="mt-2 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                     class="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-amber-600">
                                    <path fill-rule="evenodd" d="M6.701 2.25c.577-1 2.02-1 2.598 0l5.196 9a1.5 1.5 0 0 1-1.299 2.25H2.804a1.5 1.5 0 0 1-1.3-2.25l5.197-9ZM8 4a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-1.5 0v-3A.75.75 0 0 1 8 4Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
                                </svg>
                                <p class="text-xs text-amber-800">
                                    <span class="font-semibold text-amber-900">Mismatch with services.</span>
                                    Active services average {{ $derivedAverage }} min, but this is set to {{ $configuredAverage }}.
                                    Until there is enough real history, estimates use the services' average.
                                    Set this to <strong>{{ ceil($derivedAverage) }}</strong> if the difference is unintentional.
                                </p>
                            </div>
                        @elseif ($derivedAverage !== null)
                            <p class="mt-1.5 text-xs text-slate-500">
                                Matches the average duration of this window's services ({{ $derivedAverage }} min). ✓
                            </p>
                        @endif
                    </div>

                    {{-- Active / Inactive status --}}
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="is_active" value="1"
                                   @checked(old('is_active', $window->exists ? $window->is_active : true))
                                   class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-slate-800">
                                <span class="font-medium">Active</span>
                                <span class="mt-0.5 block text-xs text-slate-500">
                                    Inactive windows are hidden from clients but keep all their history and appointments.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Actions ──────────────────────────────────────────────────────── --}}
        <div class="flex items-center gap-3 pb-4">
            <button id="window-submit" type="submit"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white
                           transition-opacity hover:bg-indigo-700 focus:outline-none focus:ring-2
                           focus:ring-indigo-500 focus:ring-offset-2
                           disabled:cursor-not-allowed disabled:opacity-40">
                {{ $window->exists ? 'Save changes' : 'Create window' }}
            </button>
            <a href="{{ route('admin.windows.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold
                      text-slate-700 hover:bg-slate-50">
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
