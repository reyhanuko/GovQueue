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

            {{-- A past start date is allowed, and means something specific.

                 SlotTemplate::appliesOn() rejects only dates *before*
                 effective_from, so a past date never excludes anything: the rule
                 applies to every date the generator reaches. That is the correct
                 way to say "these hours have always been in force", and the
                 seeder relies on it. What is not valid is a rule whose whole
                 range is in the past - it matches no future date at all, which is
                 why spent rules are labelled on the list below rather than left
                 to look active. --}}
            <x-admin.field name="effective_from" id="effective_from" type="date" label="Effective from" required
                          :value="old('effective_from', today()->toDateString())"
                          help="A date in the past means these hours have always applied. Today or later means the rule starts then." />

            <x-admin.field name="effective_to" id="effective_to" type="date" label="Effective to"
                          :value="old('effective_to')"
                          help="Leave empty for open-ended. A date in the past retires the rule without deleting it." />

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
        <p class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-600">
            No templates yet. Until one exists, clients can see {{ $service->name }} in the catalogue
            but cannot book it.
        </p>
    @else
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            Each rule below says what it does in one line. Open <strong>Edit</strong> to change it.
        </p>

        <ul class="mt-4 space-y-3">
            @foreach ($templates as $template)
                @php $spent = $isSpent($template); @endphp

                <li @class([
                    'rounded-xl border bg-white',
                    'border-slate-200' => ! $spent,
                    'border-slate-200 bg-slate-50/70' => $spent,
                ])>
                    {{-- The rule in one sentence, before any inputs. Previously the
                         first thing on the card was a seven-field form, so finding
                         out what a rule did meant reading all of it. --}}
                    <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $templateSummary($template) }}
                            </p>
                            <p class="mt-1 text-xs text-slate-600">
                                {{ $template->effective_from?->format('j M Y') }}
                                &ndash;
                                {{ $template->effective_to?->format('j M Y') ?? 'no end date' }}
                                @if ($spent)
                                    <span class="mt-1 block font-semibold text-amber-800">
                                        This rule ended on
                                        {{ $template->effective_to->format('j M Y') }} and now matches no date.
                                        It is kept for the record and generates nothing.
                                    </span>
                                @elseif ($template->effective_from?->isPast())
                                    <span class="mt-1 block text-slate-500">
                                        Already in effect since {{ $template->effective_from->format('j M Y') }}.
                                    </span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @if ($spent)
                                <span class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                                    No longer applies
                                </span>
                            @elseif ($template->is_active)
                                <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                    Active
                                </span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                                    Inactive
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($templateWarning = $capacityWarning($template))
                        <p class="mx-4 mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
                            <span class="font-semibold">Staffing:</span> {{ $templateWarning }}
                        </p>
                    @endif

                    {{-- Editing is now a disclosure rather than seven fields always
                         on screen. Nothing is lost: the fields are the same ones,
                         inside, and the summary above stays put. --}}
                    <details class="border-t border-slate-100">
                        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <span>Edit this rule</span>
                            <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                            </svg>
                        </summary>

                        <div class="border-t border-slate-100 p-4">
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

                        <div class="flex flex-wrap items-end justify-between gap-3 sm:col-span-2 lg:col-span-4">
                            <div class="flex items-center gap-3">
                                <label class="flex items-center gap-2 text-sm text-slate-800">
                                    <input type="checkbox" name="is_active" value="1" id="tpl-{{ $template->id }}-active"
                                           @checked($template->is_active)
                                           class="rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                                    <span class="font-medium">Active</span>
                                </label>

                                <button type="submit"
                                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                                    Save changes
                                </button>
                            </div>

                            <p class="text-xs text-slate-500">
                                Saving does not change slots already generated. Use
                                <strong>Rebuild slots</strong> on the dashboard to apply it to dates.
                            </p>
                        </div>
                    </form>

                    {{-- Remove sits outside the disclosure: deleting a rule is not
                         something to reach by first opening "Edit this rule". --}}
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-2.5">
                        <p class="text-xs text-slate-500">
                            Deleting this rule stops new slots being generated from it. Slots already
                            generated and every existing booking are left alone.
                        </p>

                        <form method="POST" action="{{ route('admin.templates.destroy', $template) }}"
                              onsubmit="return window.govqueueConfirm
                                  ? window.govqueueConfirm(this, 'Remove this template?', @js($service->name) + ' will generate no further slots. Slots already generated are left alone, and existing bookings are unaffected.')
                                  : confirm('Remove this template? Slots already generated are left alone.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="shrink-0 rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                Remove rule
                            </button>
                        </form>
                    </div>
                        </div>
                    </details>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
