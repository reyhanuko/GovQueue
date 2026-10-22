{{--
    The slot picker: a strip of days, then one day's times.

    Shared by new bookings (client.booking.create) and by moving an existing
    appointment (client.appointments.reschedule). It was duplicated once and the
    two copies had already diverged - the move page kept the original wall and an
    ungated button, which is precisely the problem reported about the booking
    page. Sharing the markup is what keeps them identical.

    Expected variables:
      $availability     Collection, date => TimeSlots, already ordered by date
      $bookedSlotIds    date => array of slot ids this client already holds
      $selectedDate     the one day being shown; always set
      $dayLabel         fn(string $date): string
      $dayUrl           fn(string $date): string  - link for a day in the strip
      $currentAppointment  nullable Appointment, when moving an existing one

    Alpine state (selectedSlot/selectedDate/selectedTime/selectSlot) belongs to
    the enclosing <form>, because the confirm panel that reports the choice sits
    outside this partial, in a sibling column.

    min-w-0 on the <fieldset> is load-bearing. <fieldset> carries a user-agent
    default of min-inline-size:min-content, so it refuses to shrink below its own
    content regardless of what its parent allows. Measured at a 390px viewport:
    without it the fieldset laid out at 1706px and dragged the document to
    1722px, so the strip stretched the page to fit every day instead of
    scrolling inside itself. The ul's overflow-x-auto cannot break that cycle,
    because the width resolves on the fieldset's min-width, one level above the
    scroller.
--}}
<fieldset class="min-w-0 rounded-xl border border-slate-200 bg-white p-6">
    <legend class="px-1 text-sm font-bold text-slate-900">{{ $legend ?? 'Choose a time' }}</legend>

    {{-- Days, then times within the day. ~240 slot buttons become ~8. --}}
    <nav aria-label="Choose a day" class="mt-3 min-w-0">
        <ul class="flex w-full gap-2 overflow-x-auto pb-2">
            @foreach ($availability as $date => $slots)
                @php
                    $open = collect($slots)
                        ->reject(fn ($s) => in_array($s->id, $bookedSlotIds[$date] ?? [], true))
                        ->count();
                    $isCurrent = $selectedDate === $date;
                    $isBookedDay = isset($currentAppointment)
                        && $currentAppointment->scheduled_at?->toDateString() === $date;
                @endphp
                <li class="shrink-0">
                    <a href="{{ $dayUrl($date) }}"
                       @if ($isCurrent) aria-current="true" @endif
                       @if ($isCurrent && ! $isBookedDay) aria-label="{{ $dayLabel($date) }}, {{ $open }} slots free" @endif
                       class="flex w-28 flex-col items-center rounded-xl border px-2 py-2.5 transition-colors
                              {{ $isCurrent
                                  ? 'border-blue-700 bg-blue-700 text-white ring-1 ring-blue-700'
                                  : 'border-slate-200 bg-white text-slate-700 hover:border-blue-400 hover:bg-blue-50' }}">

                        {{-- Weekday, day number and month each on its own line,
                             rather than one run-on line. "Oct - 12 free" read
                             as though the month and the free count were the same
                             fact, and the 12 sat next to the 12 of the date. --}}
                        <span class="text-[11px] font-semibold uppercase leading-none tracking-wide
                                     {{ $isCurrent ? 'text-blue-100' : 'text-slate-500' }}">
                            {{ \Illuminate\Support\Carbon::parse($date)->format('D') }}
                        </span>

                        <span class="mt-1 text-2xl font-bold leading-none">
                            {{ \Illuminate\Support\Carbon::parse($date)->format('j') }}
                        </span>

                        <span class="mt-0.5 text-[11px] leading-none {{ $isCurrent ? 'text-blue-100' : 'text-slate-500' }}">
                            {{ \Illuminate\Support\Carbon::parse($date)->format('M') }}
                        </span>

                        {{-- Availability is a distinct badge, not more text, so
                             it cannot be misread as part of the date. --}}
                        @if ($open > 0)
                            <span class="mt-1.5 rounded-full px-1.5 py-0.5 text-[10px] font-bold leading-none
                                         {{ $isCurrent ? 'bg-white/20 text-white' : 'bg-emerald-50 text-emerald-700' }}">
                                {{ $open }} free
                            </span>
                        @else
                            <span class="mt-1.5 rounded-full px-1.5 py-0.5 text-[10px] font-bold leading-none
                                         {{ $isCurrent ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500' }}">
                                Full
                            </span>
                        @endif

                        @if ($isBookedDay)
                            <span class="mt-1 text-[10px] font-semibold leading-none
                                         {{ $isCurrent ? 'text-blue-100' : 'text-amber-700' }}">
                                Your booking
                            </span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Which day the times below belong to, spelled out. The strip above is
         one row of small cards, so the day is restated in words here rather
         than left to be decoded from the card. --}}
    <h2 class="mt-4 text-sm font-semibold text-slate-800">
        {{ $dayLabel($selectedDate) }}
    </h2>

    @php
        $daySlots = $availability[$selectedDate] ?? collect();
    @endphp

    @if ($daySlots->isEmpty())
        <p class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Nothing is open on this day. Pick another day from the strip above.
        </p>
    @else
        <ul id="day-{{ $selectedDate }}" class="mt-3 flex flex-wrap gap-2">
            @foreach ($daySlots as $slot)
                @php
                    $alreadyMine = in_array($slot->id, $bookedSlotIds[$selectedDate] ?? [], true);
                    $isCurrent = isset($currentAppointment)
                        && (int) $currentAppointment->time_slot_id === (int) $slot->id;
                    $disabled = $alreadyMine && ! $isCurrent;
                @endphp
                <li>
                    <label class="block cursor-pointer">
                        <input type="radio" name="time_slot_id" value="{{ $slot->id }}"
                               class="peer sr-only"
                               @checked($isCurrent)
                               @disabled($disabled)>
                        {{-- Clicking the input does not report the choice: it is
                             sr-only with this span drawn over it, so the parent
                             Alpine state is told here instead. --}}
                        <span @click="selectSlot('{{ $dayLabel($selectedDate) }}', '{{ $slot->formatTime($slot->start_time) }}', {{ $slot->id }})"
                              class="block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700
                                     peer-checked:border-blue-700 peer-checked:bg-blue-50 peer-checked:ring-1 peer-checked:ring-blue-700
                                     hover:border-blue-300 transition-colors
                                     {{ $isCurrent ? 'border-slate-400 bg-slate-100' : '' }}
                                     {{ $disabled ? 'opacity-50' : '' }}">
                            <span class="font-medium">{{ $slot->formatTime($slot->start_time) }}</span>
                            <span class="block text-xs text-slate-500">
                                @if ($isCurrent)
                                    Your current slot
                                @elseif ($alreadyMine)
                                    Already booked
                                @else
                                    {{ $slot->remainingCapacity() }}/{{ $slot->capacity }} left
                                @endif
                            </span>
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif

    @error('time_slot_id')
        <p class="mt-4 text-sm font-medium text-rose-700">{{ $message }}</p>
    @enderror
</fieldset>