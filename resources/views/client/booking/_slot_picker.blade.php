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
    @include('client.booking._day_strip')

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