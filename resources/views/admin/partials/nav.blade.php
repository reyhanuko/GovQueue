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
        /*
         * One label per group, and every item the same shape.
         *
         * The previous version stacked a page-level "Administration" label on
         * top of a first group label, so the top of the sidebar read as two
         * headings before a single link. It then showed a one-line description
         * under only the active item, which made that item twice as tall as its
         * neighbours, made the sidebar jump in height as you moved between
         * pages, and wrapped the sentence awkwardly at 15rem. The hints are gone
         * rather than shown on every row: ten of them at once is a wall of prose
         * in a 15rem column, and the page it links to explains itself.
         *
         * Group names are plain. "Everyday", "Who works here" and "Checking the
         * work" were memorable but had to be decoded; these say what is in them.
         */
        $adminGroups = [
            [
                'label' => 'Day to day',
                'links' => [
                    ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'gauge'],
                    ['route' => 'admin.slots.index', 'label' => 'Daily slots', 'icon' => 'calendar'],
                ],
            ],
            [
                'label' => 'Office setup',
                'links' => [
                    ['route' => 'admin.services.index', 'label' => 'Services', 'icon' => 'tag'],
                    ['route' => 'admin.windows.index', 'label' => 'Windows', 'icon' => 'grid'],
                    ['route' => 'admin.holidays.index', 'label' => 'Holidays', 'icon' => 'flag'],
                ],
            ],
            [
                'label' => 'People',
                'links' => [
                    ['route' => 'admin.staff.index', 'label' => 'Staff accounts', 'icon' => 'users'],
                ],
            ],
            [
                'label' => 'Records',
                'links' => [
                    ['route' => 'admin.reports.index', 'label' => 'Reports', 'icon' => 'chart'],
                    ['route' => 'admin.audit.index', 'label' => 'Audit trail', 'icon' => 'list'],
                    ['route' => 'admin.notifications.index', 'label' => 'Delivery log', 'icon' => 'bell'],
                ],
            ],
        ];

        /*
         * Icons are decorative and aria-hidden. They exist so the items read as a
         * set at a glance and can be told apart by shape, not only by wording.
         */
        $adminIcons = [
            'gauge' => '<path d="M12 6v3m0 9h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
            'calendar' => '<path d="M6.75 3v2.25M17.25 3v2.25M3.75 18.75V7.5A2.25 2.25 0 0 1 6 5.25h12a2.25 2.25 0 0 1 2.25 2.25v11.25m-18 0A2.25 2.25 0 0 0 4.5 21h15a2.25 2.25 0 0 0 2.25-2.25m-18 0v-7.5A2.25 2.25 0 0 1 6 9h12a2.25 2.25 0 0 1 2.25 2.25v7.5"/>',
            'tag' => '<path d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path d="M6 6h.008v.008H6V6Z"/>',
            'grid' => '<path d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>',
            'flag' => '<path d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5"/>',
            'users' => '<path d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>',
            'chart' => '<path d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>',
            'list' => '<path d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>',
            'bell' => '<path d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>',
        ];
    @endphp

    <nav aria-label="Administration"
         class="lg:sticky lg:top-6 lg:border-r lg:border-slate-200 lg:pr-6">
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
                    <p class="px-2 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        {{ $group['label'] }}
                    </p>
                    <ul class="space-y-0.5">
                        @foreach ($group['links'] as $link)
                            @php $current = request()->routeIs($link['route']); @endphp
                            <li>
                                <x-nav-link :href="route($link['route'])" :active="$current" class="flex items-center gap-2.5">
                                    <x-icon :name="$adminIcons[$link['icon']]" />
                                    {{ $link['label'] }}
                                </x-nav-link>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </details>

        {{-- Desktop: always visible, grouped, with the active item obvious.
             There is no page-level heading here, so the sidebar starts with a
             group label rather than two stacked labels. The nav's own
             aria-label is what names this landmark for assistive technology. --}}
        <div class="hidden lg:block">
            <div class="space-y-5">
                @foreach ($adminGroups as $group)
                    <div>
                        <p class="px-3 pb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-600">
                            {{ $group['label'] }}
                        </p>

                        <ul class="space-y-0.5">
                            @foreach ($group['links'] as $link)
                                @php $current = request()->routeIs($link['route']); @endphp
                                <li>
                                    <x-nav-link :href="route($link['route'])" :active="$current"
                                                class="flex items-center gap-2.5">
                                        <x-icon :name="$adminIcons[$link['icon']]" />
                                        {{ $link['label'] }}
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
