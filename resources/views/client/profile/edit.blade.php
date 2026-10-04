@extends('layouts.app')

@section('title', 'My Profile')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">My profile</h1>
            <p class="mt-1 text-sm text-slate-600">
                Keep your details current so the office can reach you and identify your priority correctly.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="font-bold text-slate-900">Personal details</h2>

            <form method="POST" action="{{ route('client.profile.update') }}" class="mt-4 space-y-4">
                @csrf

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="block text-sm font-medium text-slate-700">Full name</label>
                        <input id="name" name="name" type="text" required value="{{ old('name', $user->name) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                        <input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">Appointment confirmations go here.</p>
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-slate-700">Mobile number</label>
                        <input id="phone" name="phone" type="tel" required placeholder="09XXXXXXXXX"
                               value="{{ old('phone', $user->phone) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">Used for SMS reminders.</p>
                    </div>

                    <div>
                        <label for="birthdate" class="block text-sm font-medium text-slate-700">Date of birth</label>
                        <input id="birthdate" name="birthdate" type="date" required
                               value="{{ old('birthdate', $user->birthdate?->toDateString()) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">Drives your RA 9994 senior priority automatically.</p>
                    </div>

                    <div>
                        <label for="barangay" class="block text-sm font-medium text-slate-700">Barangay</label>
                        <input id="barangay" name="barangay" type="text" required
                               value="{{ old('barangay', $user->barangay) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>

                    <div class="sm:col-span-2">
                        <label for="address" class="block text-sm font-medium text-slate-700">Address</label>
                        <input id="address" name="address" type="text" value="{{ old('address', $user->address) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                </div>

                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">
                    Save changes
                </button>
            </form>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
            <h2 class="font-bold text-amber-900">Your priority eligibility</h2>
            <p class="mt-1 text-xs text-amber-800">
                Determined automatically from your profile where possible. Claims you make here are still
                verified by staff before they take effect in the queue.
            </p>

            <dl class="mt-4 space-y-3">
                @foreach ($eligibility as $category => $explanation)
                    <div class="rounded-lg bg-white/70 px-4 py-3">
                        <dt class="text-sm font-semibold text-slate-800">
                            {{ \App\Enums\PriorityType::from($category)->label() }}
                            <span class="ml-1 text-xs font-normal text-slate-500">
                                ({{ \App\Enums\PriorityType::from($category)->basis() }})
                            </span>
                        </dt>
                        <dd class="mt-1 text-sm text-slate-600">{{ $explanation }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 rounded-lg border border-amber-200 bg-white px-4 py-3 text-sm">
                <span class="font-semibold text-amber-900">Current status:</span>
                @if ($user->claims_senior_citizen)
                    <span class="text-amber-800">You are recognised as a senior citizen and will be
                    prioritised automatically once your date of birth is confirmed at the counter.</span>
                @else
                    <span class="text-amber-800">No profile-based priority applies to you. You may still
                    claim PWD or pregnant priority per transaction when booking.</span>
                @endif
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="font-bold text-slate-900">Change password</h2>

            <form method="POST" action="{{ route('client.profile.password') }}" class="mt-4 space-y-4">
                @csrf

                <div>
                    <label for="current_password" class="block text-sm font-medium text-slate-700">Current password</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirm new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                </div>

                <button type="submit"
                        class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Change password
                </button>
            </form>
        </div>
    </div>
@endsection