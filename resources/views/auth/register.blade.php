@extends('layouts.app')

@section('title', 'Create Account')

@section('content')
    <div class="mx-auto max-w-2xl">
        <div class="rounded-2xl border border-slate-200 bg-white p-8">
            <h1 class="text-2xl font-bold text-slate-900">Create your GovQueue account</h1>
            <p class="mt-1 text-sm text-slate-600">
                Register once, then book appointments for LGU Tupi frontline services.
            </p>

            <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-6"
                  x-data="{ loading: false }" @submit="loading = true">
                @csrf

                <fieldset class="space-y-4">
                    <legend class="text-sm font-bold uppercase tracking-wide text-slate-500">Personal details</legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="name" class="block text-sm font-medium text-slate-700">Full name</label>
                            <input id="name" name="name" type="text" required value="{{ old('name') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('name')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                            <input id="email" name="email" type="email" required autocomplete="email"
                                   value="{{ old('email') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('email')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-slate-500">Appointment confirmations are sent here.</p>
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-slate-700">Mobile number</label>
                            <input id="phone" name="phone" type="tel" required placeholder="09XXXXXXXXX"
                                   value="{{ old('phone') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('phone')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="birthdate" class="block text-sm font-medium text-slate-700">Date of birth</label>
                            <input id="birthdate" name="birthdate" type="date" required
                                   value="{{ old('birthdate') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('birthdate')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-slate-500">Used only to identify senior citizen priority under RA 9994.</p>
                        </div>

                        <div>
                            <label for="barangay" class="block text-sm font-medium text-slate-700">Barangay</label>
                            <input id="barangay" name="barangay" type="text" required value="{{ old('barangay') }}"
                                   list="barangay-list"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('barangay')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                            <datalist id="barangay-list">
                                @foreach (['Poblacion','Batang','Crossing','Datu Angelino','Lime','Maligunong','New Corella','Pangian','Rajah Buayan','San Agustin','San Isidro','San Jose','San Miguel','San Vicente','Tampacan','Tiwi','Tupig','Tubod'] as $b)
                                    <option value="{{ $b }}"></option>
                                @endforeach
                            </datalist>
                        </div>

                        <div class="sm:col-span-2">
                            <label for="address" class="block text-sm font-medium text-slate-700">Address <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="address" name="address" type="text" value="{{ old('address') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('address')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </fieldset>

                {{-- Priority is not claimed here.

                     The account records who somebody is. It does not record what
                     they may claim on any given transaction, so this form asks
                     for no priority input at all:

                       - Senior citizen (RA 9994) is a fact about the date of
                         birth above, so the system recognises it on its own.
                       - Person with disability (RA 10754) and pregnant-client
                         priority are claimed per transaction, on the booking,
                         where there is a document for staff to verify at the
                         counter.

                     The inputs that used to live here - a senior-citizen
                     checkbox, a PWD checkbox, a pregnancy checkbox and their
                     reference fields - were removed with this note, and the
                     claim is made where the transaction is. --}}
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                    <p class="text-sm text-amber-900">
                        <strong>Priority is claimed when you book, not when you register.</strong>
                        Your date of birth identifies senior citizen priority automatically
                        (RA 9994). Person with disability (RA 10754) and pregnant-client
                        priority are claimed on the appointment, with a reference for staff
                        to verify at the counter.
                    </p>
                </div>

                <fieldset class="space-y-4">
                    <legend class="text-sm font-bold uppercase tracking-wide text-slate-500">Security</legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                            <input id="password" name="password" type="password" required autocomplete="new-password"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('password')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirm password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required
                                   autocomplete="new-password"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                    </div>

                    <label class="flex items-start gap-3 text-sm text-slate-700">
                        <input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))
                               class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                        <span>
                            I consent to the collection and use of my personal information for the purpose of
                            scheduling and managing my municipal service transactions, in line with the Data Privacy Act of 2012.
                        </span>
                    </label>
                    @error('privacy_consent')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </fieldset>

                <button type="submit" :disabled="loading"
                        class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:opacity-60">
                    <span x-show="!loading">Create account</span>
                    <span x-show="loading" class="flex items-center justify-center gap-2" aria-live="polite">
                        <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        Creating account…
                    </span>
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Already registered?
                <a href="{{ route('login') }}" class="font-semibold text-blue-700 hover:underline">Sign in</a>
            </p>
        </div>
    </div>
@endsection