@extends('layouts.app')

@section('title', $service->exists ? 'Edit Service' : 'Add Service')

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
                <a href="{{ route('admin.services.index') }}"
                   class="hover:text-slate-700 hover:underline">Services</a>
            </li>
            <li aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 text-slate-400">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                </svg>
            </li>
            <li class="font-medium text-slate-900">{{ $service->exists ? 'Edit' : 'New' }}</li>
        </ol>
    </nav>

    <h1 class="text-2xl font-bold text-slate-900">
        {{ $service->exists ? 'Edit '.$service->name : 'Add a service' }}
    </h1>

    <form id="service-form" method="POST"
          action="{{ $service->exists ? route('admin.services.update', $service) : route('admin.services.store') }}"
          class="mt-6 max-w-2xl space-y-5">
        @csrf
        @if ($service->exists)
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
                            <path d="M4 1a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v3.879a1 1 0 0 1-.293.707l-3.5 3.5a1 1 0 0 1-1.414 0l-3.5-3.5A1 1 0 0 1 4 4.879V1ZM8 9a1 1 0 0 1 .707.293l3.5 3.5A1 1 0 0 1 11.5 14.5h-7a1 1 0 0 1-.707-1.707l3.5-3.5A1 1 0 0 1 8 9Z"/>
                        </svg>
                        Identity
                    </h2>
                </div>
                <div class="space-y-5 p-5">
                    <x-admin.field name="name" label="Service name" required
                                  :value="$service->name" placeholder="Certificate of Indigency" />

                    <x-admin.field name="code" label="Code" required
                                  :value="$service->code" placeholder="CERT-IND"
                                  help="Used in the public URL, so changing it breaks links already shared with clients." />

                    <x-admin.field name="service_window_id" type="select" label="Service window" required
                                  help="The counter clients are sent to.">
                        @forelse ($windows as $window)
                            <option value="{{ $window->id }}"
                                    @selected((int) old('service_window_id', $service->service_window_id) === (int) $window->id)>
                                {{ $window->name }}{{ $window->is_active ? '' : ' (inactive)' }}
                            </option>
                        @empty
                            <option value="" disabled>No windows configured yet</option>
                        @endforelse
                    </x-admin.field>

                    <x-admin.field name="description" type="textarea" label="Description"
                                  :value="$service->description"
                                  placeholder="What this service is for. Shown on the public catalogue." />

                    <x-admin.field name="requirements" type="textarea" label="Requirements"
                                  :value="$service->requirements"
                                  placeholder="One requirement per line"
                                  help="One per line. These are shown to clients as a checklist, so keep each item short." />
                </div>
            </div>
        </section>

        {{-- ── Card 2: Pricing & Scheduling ────────────────────────────────── --}}
        <section aria-labelledby="pricing-heading">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="pricing-heading"
                        class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="h-3.5 w-3.5">
                            <path fill-rule="evenodd" d="M15 8A7 7 0 1 1 1 8a7 7 0 0 1 14 0ZM9 5.25a.75.75 0 0 0-1.5 0V8h-.75a.75.75 0 0 0 0 1.5h.75v1.25a.75.75 0 0 0 1.5 0V9.5h.75a.75.75 0 0 0 0-1.5H9V5.25Z" clip-rule="evenodd"/>
                        </svg>
                        Pricing &amp; Scheduling
                    </h2>
                </div>
                <div class="p-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.field name="fee" type="number" label="Fee (₱)" required
                                      :value="$service->fee ?? 0" min="0" step="0.01"
                                      help="Set to 0 for a free service." />

                        <x-admin.field name="duration_minutes" type="number" label="Duration (minutes)" required
                                      :value="$service->duration_minutes ?? 15" min="1" max="480" step="1"
                                      help="Used to estimate wait times and for staffing capacity warnings." />
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Card 3: Behaviour ──────────────────────────────────────────── --}}
        <section aria-labelledby="behaviour-heading">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="behaviour-heading"
                        class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="h-3.5 w-3.5">
                            <path fill-rule="evenodd" d="M6.955 1.45A.5.5 0 0 1 7.452 1h1.096a.5.5 0 0 1 .497.45l.17 1.699c.484.19.94.443 1.355.745l1.58-.668a.5.5 0 0 1 .611.169l.548.823a.5.5 0 0 1-.088.648l-1.257 1.098c.052.286.08.580.08.879 0 .3-.028.593-.08.878l1.257 1.098a.5.5 0 0 1 .088.648l-.548.824a.5.5 0 0 1-.611.168l-1.58-.668c-.414.302-.87.555-1.354.745l-.17 1.699A.5.5 0 0 1 8.548 15H7.452a.5.5 0 0 1-.497-.45l-.17-1.699a4.973 4.973 0 0 1-1.355-.745l-1.58.668a.5.5 0 0 1-.611-.168l-.548-.824a.5.5 0 0 1 .088-.648l1.257-1.098A5.002 5.002 0 0 1 4 8c0-.3.028-.593.08-.879L2.823 6.023a.5.5 0 0 1-.088-.648l.548-.823a.5.5 0 0 1 .611-.169l1.58.668c.414-.302.871-.555 1.355-.745l.17-1.699ZM6.5 8a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z" clip-rule="evenodd"/>
                        </svg>
                        Behaviour
                    </h2>
                </div>
                <div class="space-y-3 p-5">
                    {{-- Active --}}
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="is_active" value="1"
                                   @checked(old('is_active', $service->exists ? $service->is_active : true))
                                   class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-slate-800">
                                <span class="font-medium">Active</span>
                                <span class="mt-0.5 block text-xs text-slate-500">
                                    Inactive services are hidden from the public catalogue. Existing appointments
                                    are unaffected and still appear on the staff console.
                                </span>
                            </span>
                        </label>
                    </div>

                    {{--
                        Walk-in eligibility, decided per service (D12).
                        A hidden input sends an explicit false so the column
                        is not silently left unchanged on every save.
                    --}}
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="allows_walk_in" value="1"
                                   @checked(old('allows_walk_in', $service->exists ? $service->allows_walk_in : true))
                                   class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-slate-800">
                                <span class="font-medium">Accepts walk-ins</span>
                                <span class="mt-0.5 block text-xs text-slate-500">
                                    Turn this off for services that must be booked ahead of time. Clients will be
                                    told to book an appointment, and the counter will refuse a walk-in ticket.
                                </span>
                            </span>
                        </label>
                        <input type="hidden" name="allows_walk_in_present" value="1">
                        @error('allows_walk_in')
                            <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Actions ──────────────────────────────────────────────────────── --}}
        <div class="flex flex-wrap items-center gap-3 pb-4">
            <button id="service-submit" type="submit"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white
                           transition-opacity hover:bg-indigo-700 focus:outline-none focus:ring-2
                           focus:ring-indigo-500 focus:ring-offset-2
                           disabled:cursor-not-allowed disabled:opacity-40">
                {{ $service->exists ? 'Save changes' : 'Create service' }}
            </button>

            @if ($service->exists)
                <a href="{{ route('admin.templates.index', $service) }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold
                          text-slate-700 hover:bg-slate-50">
                    Manage slot templates
                </a>
            @endif

            <a href="{{ route('admin.services.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold
                      text-slate-700 hover:bg-slate-50">
                Cancel
            </a>
        </div>
    </form>
@endsection

@if ($service->exists)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form    = document.getElementById('service-form');
    const saveBtn = document.getElementById('service-submit');
    if (!form || !saveBtn) return;

    // Snapshot includes unchecked checkboxes so toggling and restoring
    // is detected correctly.
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
