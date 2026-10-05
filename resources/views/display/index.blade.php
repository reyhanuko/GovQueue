<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Queue Display &middot; {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600,800,900" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{-- Full-bleed dark board: no site navigation, no footer. --}}
<body class="h-full bg-slate-950 font-sans antialiased">
    <a href="#display-main"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-blue-700 focus:px-4 focus:py-2 focus:text-white">
        Skip to queue information
    </a>

    <main id="display-main">
        @livewire('display.public-queue-display', [
            'windowCode' => $windowCode ?? '',
            'queueDate' => $queueDate ?? '',
        ])
    </main>

    @livewireScripts
</body>
</html>