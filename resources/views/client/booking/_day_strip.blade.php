{{--
    A horizontal strip of days, each with how many slots are open on it.

    Shared by three screens: the service page's availability preview, booking a
    new appointment, and moving an existing one. It is a partial rather than
    markup copied into each, because when it was inlined the three copies had
    already drifted apart - the service page had no strip at all and rendered the
    whole booking horizon, which is the same wall reported on the booking screen.

    Every day is a plain link carrying ?date=, so a day can be reached with no
    JavaScript. That is deliberate: this is a page a resident may be reading on a
    slow mobile connection.

    Expected variables:
      $availability     Collection, date => TimeSlots, ascending by date
      $bookedSlotIds    date => array of slot ids this client already holds
      $selectedDate     the one day being shown
      $dayUrl           fn(string $date): string
      $currentAppointment  nullable Appointment, when moving an existing one

    min-w-0 on the <nav> is load-bearing. <fieldset> carries a user-agent default
    of min-inline-size:min-content, so it refuses to shrink below its own content
    regardless of what its parent allows. Measured at a 390px viewport: without
    it the fieldset laid out at 1706px and dragged the document to 1722px, so the
    strip stretched the page to fit every day instead of scrolling inside itself.
    The ul's overflow-x-auto cannot break that cycle, because the width resolves
    on the fieldset's min-width, one level above the scroller.
--}}
<nav aria-label="Choose a day" class="min-w-0">
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
                   class="flex w-28 flex-col items-center rounded-xl border px-2 py-2.5 transition-colors
                          {{ $isCurrent
                              ? 'border-blue-700 bg-blue-700 text-white ring-1 ring-blue-700'
                              : 'border-slate-200 bg-white text-slate-700 hover:border-blue-400 hover:bg-blue-50' }}">

                    {{-- Weekday, day number and month each on its own line, rather
                         than one run-on line. "Oct - 12 free" read as though the
                         month and the free count were the same fact, and the 12
                         sat next to the 12 of the date. --}}
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

                    {{-- Availability is a distinct badge, not more text, so it
                         cannot be misread as part of the date. --}}
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