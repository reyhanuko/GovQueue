<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'GovQueue') &middot; {{ config('app.name') }}</title>

    @include('layouts.partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- For a page that needs something in the head of its own - a robots
         directive, a canonical link. The queue display uses it to stay out of
         search results, which it needs because it changes every ten seconds
         and is a board rather than a page. --}}
    @stack('head')
</head>
<body class="h-full bg-slate-50 font-sans text-slate-800 antialiased">
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to main content
    </a>

    @include('layouts.partials.navbar')

    {{-- No {{ $slot ?? '' }} here. Every view extends this layout rather than
         using it as a component, so a view that happens to use $slot as a loop
         variable leaked into this scope and printed an Eloquent model as raw
         JSON at the top of the page. --}}

    @php
        /*
         * The admin section gets a sidebar; everything else stays full width.
         *
         * Decided here rather than in each of the thirteen admin views, which
         * previously each included the nav themselves. That put the burden of
         * remembering on every new screen, and put the two-column layout in the
         * wrong file - a view could not add a page without also working out how
         * to nest itself.
         */
        $isAdminSection = auth()->check()
            && auth()->user()->isAdmin()
            && request()->routeIs('admin.*');
    @endphp

    <main id="main-content" class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @include('layouts.partials.flash')

        @if ($isAdminSection)
            <div class="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start lg:gap-8">
                @include('admin.partials.nav')

                <div class="mt-4 lg:mt-0">
                    @yield('content')
                </div>
            </div>
        @else
            @yield('content')
        @endif
    </main>

    @include('layouts.partials.footer')

    @livewireScripts
</body>
</html>