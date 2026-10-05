{{--
    Administrator section navigation.

    The admin area has grown past a single screen, so the sections are listed here
    rather than crammed into the main navbar, which only has room for a couple of
    links. Marked up as a real navigation landmark so it is reachable by keyboard
    and announced as navigation rather than as a row of links.
--}}
<nav aria-label="Administration" class="mb-6 border-b border-slate-200">
    <ul class="-mb-px flex flex-wrap gap-x-1 gap-y-1 text-sm">
        @php
            $adminLinks = [
                ['route' => 'admin.dashboard', 'label' => 'Dashboard'],
                ['route' => 'admin.reports.index', 'label' => 'Reports'],
                ['route' => 'admin.services.index', 'label' => 'Services'],
                ['route' => 'admin.windows.index', 'label' => 'Windows'],
                ['route' => 'admin.slots.index', 'label' => 'Daily slots'],
                ['route' => 'admin.holidays.index', 'label' => 'Holidays'],
                ['route' => 'admin.staff.index', 'label' => 'Staff'],
                ['route' => 'admin.audit.index', 'label' => 'Audit trail'],
                ['route' => 'admin.notifications.index', 'label' => 'Notifications'],
            ];
        @endphp

        @foreach ($adminLinks as $link)
            @php $current = request()->routeIs($link['route']); @endphp
            <li>
                <a href="{{ route($link['route']) }}"
                   @if ($current) aria-current="page" @endif
                   class="inline-block rounded-t-lg border-b-2 px-3 py-2 font-medium
                          {{ $current
                                ? 'border-blue-700 text-blue-800'
                                : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}">
                    {{ $link['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
