@extends('layouts.app')

@section('title', 'Queue Display')

@push('head')
    {{-- Not indexed: the queue display is a board, not a page anybody searches
         for, and it changes every ten seconds. --}}
    <meta name="robots" content="noindex">
@endpush

@section('content')
    {{-- A page of the site, like every other page.

         It used to be a standalone document: a full-bleed dark board with no
         site navigation, on the grounds that it is opened full-screen on a
         lobby TV. Which it is - and it is also a page a resident reaches by
         clicking "Queue Display" in the navigation or "View live queue" on
         the home page, and those people got a black page in the middle of a
         white site with no way back. --}}
    @livewire('display.public-queue-display', [
        'windowCode' => $windowCode ?? '',
        'queueDate' => $queueDate ?? '',
    ])
@endsection
