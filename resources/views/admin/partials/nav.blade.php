{{--
    Administrator navigation: a sidebar, grouped by the job rather than by table.

    Previously a single flat row of nine tabs. It read as one long list, so
    nothing said which of them were the everyday screens and which were the
    occasional ones, and on a narrow window it wrapped into two ragged rows with
    no separation. Grouped and vertical, the five screens an administrator opens
    daily sit together at the top and the rest are visibly further down.

    Rendered by layouts/app.blade.php, not included by each view: thirteen admin
    views each included it, so every one of them had to remember to, and one that
    forgot would have lost its navigation with nothing to indicate it.

    Marked as a navigation landmark with a distinct label, because the main
    navbar above is also a nav - a screen reader listing "navigation" twice with
    no way to tell them apart is worse than one.
--}}
@if (auth()->check() && auth()->user()->isAdmin())
    @php
        $adminGroups = [
            [
                'label' => 'Everyday',
                'links' => [
                    ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'hint' => 'Is the office open, and what is the queue doing?'],
                    ['route' => 'admin.slots.index', 'label' => 'Daily slots', 'hint' => 'Capacity and open/closed, day by day'],
                ],
            ],
            [
                'label' => 'What the office offers',
                'links' => [
                    ['route' => 'admin.services.index', 'label' => 'Services'],
                    ['route' => 'admin.windows.index', 'label' => 'Windows'],
                    ['route' => 'admin.holidays.index', 'label' => 'Holidays'],
                ],
            ],
            [
                'label' => 'Who works here',
                'links' => [
                    ['route' => 'admin.staff.index', 'label' => 'Staff accounts'],
                ],
            ],
            [
                'label' => 'Checking the work',
                'links' => [
                    ['route' => 'admin.reports.index', 'label' => 'Reports'],
                    ['route' => 'admin.audit.index', 'label' => 'Audit trail'],
                    ['route' => 'admin.notifications.index', 'label' => 'Notification log'],
                ],
            ],
        ];
    @endphp

    <nav aria-label="Administration" class="lg:sticky lg:top-6">
        {{-- Below lg the sidebar would push the page content a long way down, so
             the groups collapse into a disclosure instead. Same markup, no
             JavaScript, and it stays open by default on a desktop. --}}
        <details class="rounded-xl border border-slate-200 bg-white lg:hidden">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-slate-800">
                <span>Administration menu</span>
                <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </summary>

            <div class="border-t border-slate-100 px-2 py-2">
                @foreach ($adminGroups as $group)
                    <p class="px-2 pb-1 pt-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                        {{ $group['label'] }}
                    </p>
                    <ul class="space-y-0.5">
                        @foreach ($group['links'] as $link)
                            @php $current = request()->routeIs($link['route']); @endphp
                            <li>
                                <x-nav-link :href="route($link['route'])" :active="$current">
                                    {{ $link['label'] }}
                                </x-nav-link>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </details>

        {{-- Desktop: always visible, grouped, with the active item obvious. --}}
        <div class="hidden lg:block">
            <p class="px-3 pb-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                Administration
            </p>

            <div class="space-y-5">
                @foreach ($adminGroups as $group)
                    <div>
                        <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            {{ $group['label'] }}
                        </p>

                        <ul class="space-y-0.5">
                            @foreach ($group['links'] as $link)
                                @php $current = request()->routeIs($link['route']); @endphp
                                <li>
                                    <x-nav-link :href="route($link['route'])" :active="$current"
                                                class="flex flex-col">
                                        <span>{{ $link['label'] }}</span>
                                        @isset($link['hint'])
                                            {{-- Only on the item you are on. Ten hints at
                                                 once is a wall of text nobody reads. --}}
                                            @if ($current)
                                                <span class="mt-0.5 text-xs font-normal text-slate-500">{{ $link['hint'] }}</span>
                                            @endif
                                        @endisset
                                    </x-nav-link>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </nav>
@endif
