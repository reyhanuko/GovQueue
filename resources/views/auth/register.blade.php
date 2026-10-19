@extends('layouts.app')

@section('title', 'Create Account')

@section('content')
    <div class="mx-auto max-w-2xl">
        <div class="rounded-2xl border border-slate-200 bg-white p-8">
            <h1 class="text-2xl font-bold text-slate-900">Create your GovQueue account</h1>
            <p class="mt-1 text-sm text-slate-600">
                Register once, then book appointments for LGU Tupi frontline services.
            </p>

            <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-6">
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

                {{-- The priority fieldset's scope.

                     This fieldset had x-model and x-show on it and no scope for
                     either to live in, so `pwd` was undefined, the expression
                     threw, and the reference field below never actually hid.
                     The disclosure was written and did not work.

                     A reference is asked for only where one cannot be inferred.
                     The senior citizen claim is recognised from the date of birth
                     on file, so it needs none - the server fills in "Date of
                     birth on file" itself. PWD and pregnancy cannot be inferred
                     from anything, so they do, and the server refuses either of
                     those without one. The same rule, stated the same way as on
                     the booking form and at the staff walk-in panel.

                     The flags start from old(), not false. A rejected
                     registration comes back with the claims ticked, and a scope
                     that started empty would drop the claim and hide the very
                     field the client is being asked about. --}}
                <fieldset class="rounded-xl border border-amber-200 bg-amber-50 p-5"
                          x-data="{
                              pwd: {{ old('claims_pwd') ? 'true' : 'false' }},
                              pregnant: {{ old('claims_pregnant') ? 'true' : 'false' }},
                          }">
                    <legend class="px-1 text-sm font-bold text-amber-900">
                        Priority classification <span class="font-normal text-amber-700">(RA 9994 / RA 10754)</span>
                    </legend>

                    <p class="text-sm text-amber-900">
                        Tick any that apply. This is a <strong>claim only</strong> &mdash; it takes effect in the queue
                        only after staff verify it at the counter.
                    </p>

                    <div class="mt-4 space-y-3">
                        <label class="flex items-start gap-3 text-sm text-slate-800">
                            <input type="checkbox" name="claims_senior_citizen" value="1"
                                   @checked(old('claims_senior_citizen'))
                                   class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                            <span>
                                <span class="font-medium">Senior citizen (60 years old or older)</span>
                                <span class="block text-xs text-slate-600">RA 9994. Usually detected automatically from your date of birth.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 text-sm text-slate-800">
                            <input type="checkbox" name="claims_pwd" value="1" x-model="pwd"
                                   @checked(old('claims_pwd'))
                                   class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                            <span>
                                <span class="font-medium">Person with disability</span>
                                <span class="block text-xs text-slate-600">RA 10754. Requires a supporting reference below.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 text-sm text-slate-800">
                            <input type="checkbox" name="claims_pregnant" value="1" x-model="pregnant"
                                   @checked(old('claims_pregnant'))
                                   class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                            <span>
                                <span class="font-medium">Pregnant client</span>
                                <span class="block text-xs text-slate-600">Requires a supporting reference below.</span>
                            </span>
                        </label>

                        <div x-show="pwd || pregnant" x-cloak class="pt-1">
                            <label for="priority_reference" class="block text-sm font-medium text-slate-700">
                                Supporting reference number <span class="text-rose-600">*</span>
                            </label>
                            <input id="priority_reference" name="priority_reference" type="text"
                                   placeholder="e.g. PWD-1234567 or clinic referral no."
                                   value="{{ old('priority_reference') }}"
                                   class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('priority_reference')
                                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-slate-600">
                                Shown to staff for verification. Not checked against any external government database.
                            </p>
                        </div>
                    </div>
                </fieldset>

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

                <button type="submit"
                        class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">
                    Create account
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Already registered?
                <a href="{{ route('login') }}" class="font-semibold text-blue-700 hover:underline">Sign in</a>
            </p>
        </div>
    </div>
@endsection