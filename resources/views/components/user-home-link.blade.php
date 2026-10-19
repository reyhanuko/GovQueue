@props([
    'prefix' => 'Go to',
    'class' => '',
])

{{--
    A link to the screen this visitor actually has.

    Every one of these used to say "Go to my dashboard" and point at the
    /dashboard route, which redirects by role. So a member of staff was sent to
    the queue console by a link that called it a dashboard, and a client was
    sent to their appointments by the same. The destination was right; the
    name was not.

    The destination and the name now come from one place, so they cannot
    disagree - and a visitor with no account is offered the home page, which
    is the only screen they have.

    The prefix is the caller's, because "Go to" and "Back to" are different
    sentences: one is a destination on a page, the other is a way back.
--}}
@php
    $home = app(App\Services\UserHome::class);
    $visitor = auth()->user();
@endphp

<a href="{{ route($home->routeFor($visitor)) }}"
   {{ $attributes->merge(['class' => $class]) }}>
    {{ $prefix }} {{ $home->labelFor($visitor) }}
</a>
