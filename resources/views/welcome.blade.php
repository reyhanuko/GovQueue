@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <section class="rounded-2xl bg-gradient-to-br from-blue-700 to-blue-900 px-6 py-14 text-white sm:px-10">
        <p class="text-sm font-semibold uppercase tracking-wider text-blue-200">Municipal Government of Tupi</p>
        <h1 class="mt-3 max-w-3xl text-3xl font-black leading-tight sm:text-4xl">
            Book your appointment. Skip the long queue.
        </h1>
        <p class="mt-4 max-w-2xl text-blue-100">
            GovQueue replaces the manual, walk-in-only process at the LGU of Tupi with an
            appointment-driven queue system &mdash; so you arrive when you are expected to be served.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('register') }}"
               class="rounded-lg bg-white px-5 py-3 text-sm font-bold text-blue-800 hover:bg-blue-50">
                Create an account
            </a>
            <a href="{{ route('services.index') }}"
               class="rounded-lg border border-white/40 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10">
                Browse services
            </a>
            <a href="{{ route('display.index') }}"
               class="rounded-lg border border-white/40 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10">
                View live queue
            </a>
        </div>
    </section>

    <section class="mt-10" aria-labelledby="features-heading">
        <h2 id="features-heading" class="text-xl font-bold text-slate-900">What GovQueue does</h2>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @php
                $features = [
                    ['Online Appointment Scheduling',
                     'Book a time slot within the capacity the office set, and the system blocks conflicting or over-capacity bookings automatically.'],
                    ['Real-Time Queue Tracking',
                     'Watch your queue position and estimated waiting time update live, on the public display and on your own account.'],
                    ['Priority-Based Queue Automation',
                     'Senior citizens, persons with disability, and pregnant clients are reordered automatically under RA 9994 and RA 10754.'],
                    ['Automated Client Notification',
                     'Get a confirmation when you book, a reminder before your slot, and an alert if your schedule changes.'],
                    ['Analytics and Reporting',
                     'Administrators see daily volume, peak-hour trends, wait times, and service performance.'],
                    ['User Account Management',
                     'Separate client, staff, and administrator accounts, each with role-based access.'],
                ];
            @endphp

            @foreach ($features as [$title, $body])
                <article class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="font-bold text-slate-900">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $body }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mt-10 grid gap-4 md:grid-cols-3" aria-labelledby="how-heading">
        <h2 id="how-heading" class="sr-only">How it works</h2>

        @foreach ([
            ['1. Register', 'Create your client account and tell us if you qualify for priority under RA 9994 or RA 10754.'],
            ['2. Book', 'Pick a service and an available time slot. You get a queue number immediately.'],
            ['3. Arrive & get notified', 'Watch your position live and come to your window when your number is called.'],
        ] as [$title, $body])
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-bold text-blue-700">{{ $title }}</h3>
                <p class="mt-2 text-sm text-slate-600">{{ $body }}</p>
            </div>
        @endforeach
    </section>
@endsection