@extends('layouts.app')

@section('title', $service->exists ? 'Edit Service' : 'Add Service')

@section('content')
    @include('admin.partials.nav')

    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('admin.services.index') }}" class="text-blue-700 hover:underline">Services</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">{{ $service->exists ? 'Edit' : 'New' }}</span>
    </nav>

    <h1 class="mt-4 text-2xl font-bold text-slate-900">
        {{ $service->exists ? 'Edit '.$service->name : 'Add a service' }}
    </h1>

    <form method="POST"
          action="{{ $service->exists ? route('admin.services.update', $service) : route('admin.services.store') }}"
          class="mt-6 max-w-2xl space-y-5">
        @csrf
        @if ($service->exists)
            @method('PUT')
        @endif

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <x-admin.field name="name" label="Service name" required
                          :value="$service->name" placeholder="Certificate of Indigency" />

            <div class="mt-5">
                <x-admin.field name="code" label="Code" required
                              :value="$service->code" placeholder="CERT-IND"
                              help="Used in the public URL, so changing it breaks links already shared with clients." />
            </div>

            <div class="mt-5">
                <x-admin.field name="service_window_id" type="select" label="Service window" required
                              help="The counter clients are sent to.">
                    @foreach ($windows as $window)
                        <option value="{{ $window->id }}"
                                @selected((int) old('service_window_id', $service->service_window_id) === (int) $window->id)>
                            {{ $window->name }}{{ $window->is_active ? '' : ' (inactive)' }}
                        </option>
                    @endforeach
                </x-admin.field>
            </div>

            <div class="mt-5">
                <x-admin.field name="description" type="textarea" label="Description"
                              :value="$service->description"
                              placeholder="What this service is for. Shown on the public catalogue." />
            </div>

            <div class="mt-5">
                <x-admin.field name="requirements" type="textarea" label="Requirements"
                              :value="$service->requirements"
                              placeholder="One requirement per line"
                              help="One per line. These are shown to clients as a checklist, so keep each line short." />
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-admin.field name="fee" type="number" label="Fee (₱)" required
                              :value="$service->fee ?? 0" min="0" step="0.01"
                              help="0 means free." />

                <x-admin.field name="duration_minutes" type="number" label="Duration (minutes)" required
                              :value="$service->duration_minutes ?? 15" min="1" max="480" step="1"
                              help="Used for wait-time estimates." />
            </div>

            <div class="mt-5">
                <label class="flex items-start gap-3 text-sm text-slate-800">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $service->exists ? $service->is_active : true))
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                    <span>
                        <span class="font-medium">Active</span>
                        <span class="block text-xs text-slate-500">
                            Inactive services stay off the public catalogue. Existing appointments are
                            unaffected and still appear on the staff console.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                {{ $service->exists ? 'Save changes' : 'Create service' }}
            </button>

            @if ($service->exists)
                <a href="{{ route('admin.templates.index', $service) }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Manage slot templates
                </a>
            @endif

            <a href="{{ route('admin.services.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Cancel
            </a>
        </div>
    </form>
@endsection
