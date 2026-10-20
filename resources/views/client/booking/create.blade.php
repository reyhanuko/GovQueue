@extends('layouts.app')

@section('title', 'Book '.$service->name)

@section('content')
    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('services.index') }}" class="text-blue-700 hover:underline">All services</a>
        <span class="mx-1 text-slate-400">/</span>
        <a href="{{ route('services.show', $service) }}" class="text-blue-700 hover:underline">{{ $service->name }}</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">Choose a slot</span>
    </nav>

    <div class="mt-4">
        <h1 class="text-2xl font-bold text-slate-900">Book {{ $service->name }}</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ $service->window->name }} &middot; about {{ $duration['minutes'] }} minutes
            @if ($service->fee > 0)
                &middot; &#8369;{{ number_format((float) $service->fee, 2) }} payable at the counter
            @endif
        </p>
    </div>

    @if ($hasActiveBooking)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-900">You already hold a booking for this service</h2>
            <p class="mt-1 text-sm text-amber-800">
                @if ($activeBookings->isNotEmpty())
                    One live booking per service keeps the queue fair. Move your existing
                    booking instead of creating a second one.
                @else
                    {{-- The booking is live but cannot be moved: the client is
                         already at the office, or their ticket has been sent
                         back to the end of the line. Either way the server
                         will not let them book a second one, so the page says
                         so rather than naming a reason that is only true of
                         some of them. --}}
                    One live booking per service keeps the queue fair, and the one
                    you hold cannot be moved right now - so a second booking would
                    put you in the line twice.
                @endif
            </p>
            <ul class="mt-3 space-y-2">
                @foreach ($activeBookings as $existing)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white px-4 py-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">
                                {{ $existing->scheduledDateLabel() }} &middot; {{ $existing->scheduledTimeLabel() }}
                            </p>
                            <p class="font-mono text-xs text-slate-500">{{ $existing->reference_number }}</p>
                        </div>
                        <a href="{{ route('client.appointments.reschedule', $existing) }}"
                           class="rounded-lg border border-amber-500 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100">
                            Move this appointment
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($availability->isEmpty())
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="text-sm font-medium text-slate-700">No open slots available for this service.</p>
            <p class="mt-1 text-sm text-slate-500">
                Every slot in the coming weeks is either full or the window is closed.
                Walk-ins are still accommodated through the office's existing process.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('client.booking.store', $service) }}" 
              class="mt-6 grid gap-6 lg:grid-cols-3"
              x-data="{ 
                  selectedSlot: null, 
                  selectedDate: null, 
                  selectedTime: null,
                  selectSlot(date, time, slotId) {
                      this.selectedDate = date;
                      this.selectedTime = time;
                      this.selectedSlot = slotId;
                  }
              }">
            @csrf

            {{-- min-w-0 is load-bearing, not decoration. A grid item defaults to
                 min-width:auto, and <fieldset> carries a user-agent default of
                 min-inline-size:min-content, so between them this column
                 refuses to shrink below its widest content. The day strip is
                 meant to scroll sideways inside itself; without these two the
                 strip instead stretched the whole page to fit all thirty days,
                 which is the same wall the strip was added to remove. --}}
            <div class="min-w-0 space-y-5 lg:col-span-2">
                {{-- Selection feedback.

                     This used to tell the reader to "scroll down to complete your
                     booking", which described the problem instead of solving it -
                     step 1 was a wall, so scrolling was the only way past it.
                     Step 1 is short now, and the chosen slot is repeated in the
                     confirm panel beside the button, which is where somebody
                     about to commit actually looks. --}}
                <div x-show="selectedSlot"
                     x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 transform -translate-y-2"
                     x-transition:enter-end="opacity-100 transform translate-y-0"
                     class="rounded-xl border border-green-200 bg-green-50 p-4 shadow-sm">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 flex-shrink-0 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-green-900">Time slot selected</p>
                            <p class="mt-1 text-sm text-green-800">
                                <span x-text="selectedDate"></span> at <span x-text="selectedTime"></span>
                            </p>
                            <p class="mt-1 text-xs text-green-700">
                                Check the panel on the right, then confirm.
                            </p>
                        </div>
                    </div>
                </div>

                @include('client.booking._slot_picker', [
                    'legend' => '1. Choose a time slot',
                ])

                {{-- The priority fieldset's scope.

                     A reference is asked for only where one cannot be inferred.
                     The senior citizen claim is recognised from the date of birth
                     on file, so it needs none; PWD and pregnancy cannot be
                     inferred from anything, so they do, and the server refuses
                     either of those without one. The staff walk-in panel applies
                     the same rule, and so does registration.

                     Which claims need one is not decided here. $priorities carries
                     requires_reference, and this follows it.

                     The flags start from old(), not false. A rejected booking
                     comes back with the claims ticked, and a scope that started
                     empty would drop the claim and hide the very field the
                     client is being asked about. --}}
                <fieldset class="rounded-xl border border-amber-200 bg-amber-50 p-6"
                          x-data="{
                              claims: {
                                  @foreach ($priorities as $key => $meta)
                                      @if ($meta['requires_reference'])
                                          '{{ $key }}': {{ old('claims_'.$key) ? 'true' : 'false' }},
                                      @endif
                                  @endforeach
                              },
                              get needsReference() {
                                  return Object.values(this.claims).some(Boolean);
                              },
                          }">
                    <legend class="px-1 text-sm font-bold text-amber-900">
                        2. Priority claim <span class="font-normal text-amber-700">(optional)</span>
                    </legend>

                    <p class="text-sm text-amber-900">
                        Claiming priority here does <strong>not</strong> move you up the queue yet.
                        Staff must verify your claim at the counter first.
                    </p>

                    <div class="mt-4 space-y-3">
                        @foreach ($priorities as $key => $meta)
                            <label class="flex items-start gap-3 text-sm text-slate-800
                                          {{ $meta['available'] ? '' : 'opacity-50' }}">
                                <input type="checkbox" name="claims_{{ $key }}" value="1"
                                       @if ($meta['requires_reference']) x-model="claims.{{ $key }}" @endif
                                       @disabled(! $meta['available'])
                                       @checked(old('claims_'.$key))
                                       class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                                <span>
                                    <span class="font-medium">{{ $meta['label'] }}</span>
                                    <span class="ml-1 text-xs text-slate-500">({{ $meta['basis'] }})</span>
                                    @if ($key === 'senior_citizen')
                                        <span class="block text-xs text-slate-600">
                                            @if ($meta['available'])
                                                Recognised from your date of birth.
                                            @else
                                                Your date of birth does not yet meet the RA 9994 threshold of
                                                {{ config('govqueue.senior_citizen_age') }}.
                                            @endif
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @endforeach

                        {{-- No x-cloak, and that is deliberate.

                             A field the client may be *required* to fill in must
                             never depend on a script having run. The project has
                             no [x-cloak] { display: none } rule, so x-cloak does
                             nothing here today - but the day one is added, this
                             field would be invisible to anybody without
                             JavaScript, and the claim it verifies would be
                             impossible to make.

                             The cost is a brief flash of the field before Alpine
                             hides it, which is a better trade than a field that
                             is sometimes not there at all. --}}
                        <div x-show="needsReference">
                            <label for="priority_reference" class="block text-sm font-medium text-slate-700">
                                Supporting reference <span class="text-rose-600">*</span>
                            </label>
                            <input id="priority_reference" name="priority_reference" type="text"
                                   placeholder="e.g. PWD-1234567 or clinic referral no."
                                   value="{{ old('priority_reference') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('priority_reference')
                                <p class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-slate-500">
                                Staff need this to verify your claim at the counter.
                            </p>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="min-w-0 rounded-xl border border-slate-200 bg-white p-6">
                    <legend class="px-1 text-sm font-bold text-slate-900">3. Anything staff should know?</legend>
                    <label for="client_note" class="sr-only">Note for staff</label>
                    <textarea id="client_note" name="client_note" rows="3"
                              placeholder="Optional. For example: I will bring my original birth certificate."
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('client_note') }}</textarea>
                    @error('client_note')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </fieldset>
            </div>

            <aside>
                <div class="sticky top-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="font-bold text-slate-900">Confirm your booking</h2>

                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Service</dt>
                            <dd class="text-right font-medium text-slate-800">{{ $service->name }}</dd>
                        </div>
                        {{-- The slot the reader chose, next to the button that commits
                             them to it. Previously the only confirmation of a
                             selection was a banner at the top of a long page. --}}
                        <div class="flex justify-between gap-2" x-show="selectedSlot" x-cloak>
                            <dt class="text-slate-500">Your slot</dt>
                            <dd class="text-right font-medium text-slate-800">
                                <span x-text="selectedDate"></span><br>
                                <span x-text="selectedTime"></span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2" x-show="! selectedSlot" x-cloak>
                            <dt class="text-slate-600">Your slot</dt>
                            <dd class="text-right text-slate-500">Not chosen yet</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Window</dt>
                            <dd class="text-right font-medium text-slate-800">{{ $service->window->name }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Fee</dt>
                            <dd class="text-right font-medium text-slate-800">
                                {{--
                                    The peso sign is an entity, in raw template
                                    text rather than inside the {{ }} expression.

                                    Two reasons, both learned the hard way. An
                                    entity inside {{ }} is escaped, so it reaches
                                    the browser as the literal characters
                                    "&#8369;" and renders as text. And a literal
                                    peso character is destroyed by any tooling that
                                    reads the file as Windows-1252 and writes it
                                    back as UTF-8 - which is how this came to read
                                    as three characters instead of one glyph.
                                --}}
                                @if ($service->fee > 0)
                                    &#8369;{{ number_format((float) $service->fee, 2) }}
                                @else
                                    Free
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- Refuses to submit until a slot is chosen, rather than
                         accepting it and bouncing the reader off to a validation
                         error at the top of a page they have to scroll down
                         again to fix. A disabled button on its own says
                         nothing, so the reason is spelled out beneath it. --}}
                    <button type="submit" @disabled($hasActiveBooking)
                            x-bind:disabled="! selectedSlot"
                            class="mt-5 w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                        {{ $hasActiveBooking ? 'Booking already held' : 'Confirm appointment' }}
                    </button>

                    <p x-show="! selectedSlot && ! {{ $hasActiveBooking ? 'true' : 'false' }}" x-cloak
                       class="mt-2 text-xs text-slate-500">
                        Choose a time in step 1 first.
                    </p>

                    <p class="mt-3 text-xs leading-relaxed text-slate-500">
                        Your slot is only reserved once you see a queue number. You will receive a
                        confirmation, and a reminder before your appointment.
                    </p>
                </div>
            </aside>
        </form>
    @endif
@endsection
