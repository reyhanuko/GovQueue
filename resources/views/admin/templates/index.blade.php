@extends('layouts.app')

@section('title', 'Slot Templates')

@section('content')
    {{-- Fix #3: chevron breadcrumb, consistent with service/window forms --}}
    <nav class="flex items-center gap-1.5 text-sm" aria-label="Breadcrumb">
        <a href="{{ route('admin.services.index') }}" class="text-slate-500 hover:text-slate-700">Services</a>
        <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
        <span class="font-medium text-slate-800">{{ $service->name }}</span>
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

    {{-- Fix #5: small-caps heading consistent with rest of admin section headings --}}
    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6" aria-labelledby="add-template">
        <h2 id="add-template" class="text-xs font-semibold uppercase tracking-widest text-slate-500">Add a template</h2>

        <form method="POST" action="{{ route('admin.templates.store', $service) }}"
              class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @csrf

            {{--
                Three flat fields in one row so their labels and inputs sit at the
                same vertical baseline.  The fieldset+legend+description that was
                here before pushed the time inputs ~40 px below the Day select,
                making the row look uneven.  The 24-hour hint now lives directly
                on each time field via the help prop.
            --}}
            <x-admin.field name="day_of_week" id="day_of_week" type="select" label="Day" required
                          help="Only days the office is open are listed.">
                @php
                    $dayNames = \App\Models\SlotTemplate::dayNames();
                    $openDays = array_map('intval', (array) config('govqueue.open_days', [1, 2, 3, 4, 5]));
                    // An empty list means "no restriction", matching the server
                    // guard; offering nothing would be its own bug.
                    $offered = $openDays !== [] ? $openDays : [0, 1, 2, 3, 4, 5, 6];
                    $defaultDay = in_array(1, $offered, true) ? 1 : (int) reset($offered);
                @endphp
                @foreach ($offered as $dayNumber)
                    <option value="{{ $dayNumber }}" @selected((int) old('day_of_week', $defaultDay) === $dayNumber)>
                        {{ $dayNames[$dayNumber] }}
                    </option>
                @endforeach
            </x-admin.field>

            <x-admin.field name="start_time" id="start_time" type="time" label="Opens at" required
                          :value="old('start_time', '08:00')"
                          placeholder="08:00" help="24-hour time, e.g. 08:00 or 13:00." />

            <x-admin.field name="end_time" id="end_time" type="time" label="Closes at" required
                          :value="old('end_time', '12:00')"
                          placeholder="12:00" help="Must be after Opens at." />

            <x-admin.field name="slot_minutes" id="slot_minutes" type="number" label="Slot length (minutes)" required
                          :value="old('slot_minutes', 30)" min="5" max="480" step="5"
                          help="Must fit inside the opening hours." />

            <x-admin.field name="capacity" id="capacity" type="number" label="Places per slot" required
                          :value="old('capacity', 5)" min="1" max="500" step="1"
                          help="Applies to slots with no bookings yet. A warning appears if the window has too few staff to serve this many." />

            {{--
                The window-level shared budget (decision D1).

                This is the screen where capacity is set, so it is where a window
                quietly gets overcommitted: add a third service at four places and
                each one is individually fine while the clerk is promised twelve.

                Sits directly under the field it relates to, because it is a
                consequence of the number being typed there and of the other
                services already configured - not a separate opinion.
            --}}
            @if ($aggregateWarning)
                <div role="status"
                     class="sm:col-span-2 lg:col-span-3 rounded-lg border border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-semibold">This window is overcommitted as a whole</p>
                    <p class="mt-1 text-xs">{{ $aggregateWarning }}</p>
                </div>
            @endif

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
                {{-- Fix #2: correct reference — rebuild moved from dashboard to Daily Slots --}}
                <p class="ml-3 text-xs text-slate-500">
                    After adding, use <strong>Rebuild slots</strong> on the Daily Slots page to apply it to dates.
                </p>
            </div>
        </form>
    </section>

    {{-- Existing templates --}}
    {{-- Fix #5: small-caps heading --}}
    <h2 class="mt-8 text-xs font-semibold uppercase tracking-widest text-slate-500">Configured templates</h2>

    @if ($templates->isEmpty())
        {{-- Fix #6: empty state with icon --}}
        <div class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center">
            <svg class="mx-auto h-8 w-8 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
            <p class="mt-2 text-sm font-medium text-slate-700">No templates yet</p>
            <p class="mx-auto mt-1 max-w-sm text-xs text-slate-500">
                Until one exists, clients can see {{ $service->name }} in the catalogue but cannot book it.
            </p>
        </div>
    @else
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            Each rule below says what it does in one line. Open <strong>Edit</strong> to change it.
        </p>

        <ul class="mt-4 space-y-3">
            @foreach ($templates as $template)
                @php $spent = $isSpent($template); @endphp

                {{-- Fix #7: spent cards get a dashed border so they're visually distinct at a glance --}}
                <li @class([
                    'rounded-xl border bg-white',
                    'border-slate-200' => ! $spent,
                    'border-dashed border-slate-300 bg-slate-50/60' => $spent,
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
                    {{-- Fix #4: group on <details> so chevron rotates via group-open: --}}
                    <details class="group border-t border-slate-100">
                        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <span>Edit this rule</span>
                            <svg class="h-3.5 w-3.5 text-slate-400 transition-transform group-open:rotate-180"
                                 viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
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
                            @foreach (\App\Models\SlotTemplate::dayNames() as $index => $day)
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
                                {{-- Fix #2: correct reference to Daily Slots page --}}
                                <strong>Rebuild slots</strong> on the Daily Slots page to apply changes to dates.
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
                                  ? window.govqueueConfirm(this, 'Remove this template?', {!! e(json_encode($service->name.' will generate no further slots. Slots already generated are left alone, and existing bookings are unaffected.')) !!})
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

    {{-- Danger zone --------------------------------------------------------- --}}
    <section class="mt-10 rounded-xl border border-rose-200 bg-rose-50/40 p-5"
             aria-labelledby="danger-zone-heading">
        <h2 id="danger-zone-heading"
            class="text-xs font-semibold uppercase tracking-widest text-rose-700">Danger zone</h2>

        <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-lg">
                <p class="text-sm font-medium text-slate-800">Clear generated slots</p>
                <p class="mt-1 text-xs text-slate-600">
                    Use this to undo a rebuild from a wrong template. Only <strong>future, unbooked</strong>
                    slots are removed. Slots that already have a client booking are left untouched.
                </p>

                {{-- Show what will happen vs what will survive --}}
                @if ($futureTotalSlotCount === 0)
                    <p class="mt-1.5 text-xs text-slate-400">No future slots exist for this service right now.</p>
                @else
                    @php $bookedCount = $futureTotalSlotCount - $futureUnbookedSlotCount; @endphp
                    <div class="mt-2 flex flex-wrap gap-3 text-xs">
                        <span class="{{ $futureUnbookedSlotCount > 0 ? 'text-rose-700 font-semibold' : 'text-slate-400' }}">
                            {{ number_format($futureUnbookedSlotCount) }} unbooked
                            — {{ $futureUnbookedSlotCount > 0 ? 'will be deleted' : 'nothing to clear' }}
                        </span>
                        @if ($bookedCount > 0)
                            <span class="text-slate-500">
                                {{ number_format($bookedCount) }} {{ Str::plural('slot', $bookedCount) }} with bookings — will stay
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            <form method="POST"
                  action="{{ route('admin.templates.clearSlots', $service) }}"
                  onsubmit="return window.govqueueConfirm
                      ? window.govqueueConfirm(this, 'Clear generated slots?',
                          {!! e(json_encode(number_format($futureUnbookedSlotCount).' unbooked future '.Str::plural('slot', $futureUnbookedSlotCount).' for '.$service->name.' will be permanently deleted. Slots with bookings are left alone.')) !!})
                      : confirm('Clear {{ number_format($futureUnbookedSlotCount) }} unbooked slots? This cannot be undone.');">
                @csrf
                @method('DELETE')
                <button type="submit"
                        @disabled($futureUnbookedSlotCount === 0)
                        class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700
                               hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40">
                    Clear slots
                </button>
            </form>
        </div>
    </section>
@endsection

