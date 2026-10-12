@extends('layouts.app')

@section('title', 'Slot Templates')

@section('content')
    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('admin.services.index') }}" class="text-blue-700 hover:underline">Services</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">{{ $service->name }}</span>
    </nav>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Slot templates</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                The recurring rule for {{ $service->name }}. Concrete bookable slots are expanded from
                these by rebuilding slots; existing slots are updated, never duplicated.
            </p>
        </div>
        <a href="{{ route('admin.services.edit', $service) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Edit service
        </a>
    </div>

    {{-- Add a template --}}
    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6" aria-labelledby="add-template">
        <h2 id="add-template" class="text-lg font-bold text-slate-900">Add a template</h2>

        <form method="POST" action="{{ route('admin.templates.store', $service) }}"
              class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @csrf

            <x-admin.field name="day_of_week" id="day_of_week" type="select" label="Day" required>
                {{-- Defaults to Monday: the LGU's working week starts there, and
                     Sunday is almost never what an administrator means by the
                     first option in the list. --}}
                @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $index => $day)
                    <option value="{{ $index }}" @selected((int) old('day_of_week', 1) === $index)>{{ $day }}</option>
                @endforeach
            </x-admin.field>

            {{-- The pair is labelled once, as a range, because "Opens" and "Closes"
                 on two separate inputs left it ambiguous which was which when a
                 validation error named one of them. --}}
            <fieldset class="sm:col-span-2">
                <legend class="text-sm font-medium text-slate-700">Opening hours</legend>
                <p class="mt-0.5 text-xs text-slate-500">
                    24-hour time. Slots are generated from this range and must fit inside it.
                </p>

                <div class="mt-2 grid gap-4 sm:grid-cols-2">
                    <x-admin.field name="start_time" id="start_time" type="time" label="Opens at" required
                                  :value="old('start_time', '08:00')"
                                  placeholder="08:00" help="For example 08:00, or 13:00 for 1pm." />
                    <x-admin.field name="end_time" id="end_time" type="time" label="Closes at" required
                                  :value="old('end_time', '12:00')"
                                  placeholder="12:00" help="For example 12:00, or 17:00 for 5pm." />
                </div>
            </fieldset>

            <x-admin.field name="slot_minutes" id="slot_minutes" type="number" label="Slot length (minutes)" required
                          :value="old('slot_minutes', 30)" min="5" max="480" step="5"
                          help="Must fit inside the opening hours." />

            <x-admin.field name="capacity" id="capacity" type="number" label="Places per slot" required
                          :value="old('capacity', 5)" min="1" max="500" step="1"
                          help="Applies to slots with no bookings yet. A warning appears if the window has too few staff to serve this many." />

            {{-- Live preview of the staffing check on the template being added.
                 A warning, never a block: only the administrator knows whether
                 a clerk is being shared across windows. --}}
            <div x-data="{
                    capacity: {{ (int) old('capacity', 5) }},
                    slotMinutes: {{ (int) old('slot_minutes', 30) }},
                    servable: {{ app(\App\Services\StaffingCapacityService::class)->placesServable(app(\App\Services\StaffingCapacityService::class)->staffCount($service->window), (int) old('slot_minutes', 30), (int) $service->duration_minutes) }},
                    get over() { return this.capacity > this.servable; }
                 }"
                 class="sm:col-span-2 lg:col-span-3">
                <template x-if="over">
                    <div role="status" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <p class="font-semibold">This window cannot serve that many clients</p>
                        <p class="mt-1" x-text="`${$service->window->name} has ${app(\App\Services\StaffingCapacityService::class)->staffCount($service->window)} active staff, so a ${slotMinutes}-minute slot can serve about ${servable} ${$service->name} at ${$service->duration_minutes} minutes each. Clients booked beyond that will wait longer than the estimates given to them.`"></p>
                    </div>
                </template>
            </div>

            <x-admin.field name="effective_from" id="effective_from" type="date" label="Effective from" required
                          :value="old('effective_from', today()->toDateString())" />

            <x-admin.field name="effective_to" id="effective_to" type="date" label="Effective to"
                          :value="old('effective_to')"
                          help="Leave empty for open-ended." />

            <div class="flex items-end">
                <label class="flex items-start gap-3 pb-2 text-sm text-slate-800">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                    <span class="font-medium">Active</span>
                </label>
            </div>

            <div class="flex items-end sm:col-span-2 lg:col-span-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                    Add template
                </button>
                <p class="ml-3 text-xs text-slate-500">
                    After adding, use <strong>Rebuild slots</strong> on the dashboard to apply it to dates.
                </p>
            </div>
        </form>
    </section>

    {{-- Existing templates --}}
    <h2 class="mt-8 text-lg font-bold text-slate-900">Configured templates</h2>

    @if ($templates->isEmpty())
        <p class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500">
            No templates yet. Until one exists, clients can see {{ $service->name }} in the catalogue
            but cannot book it.
        </p>
    @else
        <ul class="mt-3 space-y-3">
            @foreach ($templates as $template)
                <li class="rounded-xl border border-slate-200 bg-white p-4">
                    <form method="POST" action="{{ route('admin.templates.update', $template) }}"
                          class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf
                        @method('PUT')

                        {{-- Each template has its own form on the page, so every
                             field needs its own id. Sharing them made
                             label[for] and aria-describedby resolve to the first
                             copy in the document instead of this one. --}}
                        <x-admin.field name="day_of_week" :use-old="false" :id="'tpl-'.$template->id.'-day'" type="select"
                                      label="Day" required>
                            @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $index => $day)
                                <option value="{{ $index }}" @selected((int) $template->day_of_week === $index)>{{ $day }}</option>
                            @endforeach
                        </x-admin.field>

                        <x-admin.field name="start_time" :use-old="false" :id="'tpl-'.$template->id.'-start'" type="time" label="Opens at" required
                                      :value="$template->start_time" placeholder="08:00" />

                        <x-admin.field name="end_time" :use-old="false" :id="'tpl-'.$template->id.'-end'" type="time" label="Closes at" required
                                      :value="$template->end_time" placeholder="12:00" />

                        <x-admin.field name="slot_minutes" :use-old="false" :id="'tpl-'.$template->id.'-minutes'" type="number"
                                      label="Slot length (min)" required
                                      :value="$template->slot_minutes" min="5" max="480" step="5" />

                        <x-admin.field name="capacity" :use-old="false" :id="'tpl-'.$template->id.'-capacity'" type="number"
                                      label="Places per slot" required
                                      :value="$template->capacity" min="1" max="500" step="1" />

                        <x-admin.field name="effective_from" :use-old="false" :id="'tpl-'.$template->id.'-from'" type="date"
                                      label="Effective from" required
                                      :value="$template->effective_from?->toDateString()" />

                        <x-admin.field name="effective_to" :use-old="false" :id="'tpl-'.$template->id.'-to'" type="date"
                                      label="Effective to"
                                      :value="$template->effective_to?->toDateString()" />

                        @php $templateWarning = $capacityWarning($template); @endphp

                        {{-- Staffing warning for this saved template. A warning,
                             never a block: the administrator may be planning to
                             add staff, or may be sharing a clerk deliberately. --}}
                        @if ($templateWarning)
                            <p class="sm:col-span-2 lg:col-span-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <span class="font-semibold">Staffing:</span> {{ $templateWarning }}
                            </p>
                        @endif

                        <div class="flex items-end gap-2">
                            <label class="flex items-center gap-2 pb-2 text-sm text-slate-800">
                                <input type="checkbox" name="is_active" value="1" id="tpl-{{ $template->id }}-active"
                                       @checked($template->is_active)
                                       class="rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                                <span class="text-xs font-medium">Active</span>
                            </label>

                            <button type="submit"
                                    class="mb-1 rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-900">
                                Save
                            </button>
                        </div>
                    </form>

                    <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                        <p class="text-xs text-slate-500">
                            {{ $template->effective_from?->format('j M Y') }}
                            &ndash;
                            {{ $template->effective_to?->format('j M Y') ?? 'open-ended' }}
                            @unless ($template->is_active)
                                <span class="ml-1 font-medium text-amber-700">(inactive)</span>
                            @endunless
                        </p>

                        <form method="POST" action="{{ route('admin.templates.destroy', $template) }}"
                              onsubmit="return window.govqueueConfirm
                                  ? window.govqueueConfirm(this, 'Remove this template?', @js($service->name) + ' will generate no further slots. Slots already generated are left alone, and existing bookings are unaffected.')
                                  : confirm('Remove this template? Slots already generated are left alone.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                Remove
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
