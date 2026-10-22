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

            <form method="POST" action="{{ route('client.profile.update') }}" data-track-dirty class="mt-4 space-y-4">
                @csrf
                {{-- Spoofs PUT, which a browser form cannot send directly. Without
                     this the whole profile screen returned 405. --}}
                @method('PUT')

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="block text-sm font-medium text-slate-700">Full name</label>
                        <input id="name" name="name" type="text" required value="{{ old('name', $user->name) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('name')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                        <input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">Appointment confirmations go here.</p>
                        @error('email')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-slate-700">Mobile number</label>
                        <input id="phone" name="phone" type="tel" required placeholder="09XXXXXXXXX"
                               value="{{ old('phone', $user->phone) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">Used for SMS reminders.</p>
                        @error('phone')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="birthdate" class="block text-sm font-medium text-slate-700">Date of birth</label>
                        <input id="birthdate" name="birthdate" type="date" required
                               value="{{ old('birthdate', $user->birthdate?->toDateString()) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">Drives your RA 9994 senior priority automatically.</p>
                        @error('birthdate')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="barangay" class="block text-sm font-medium text-slate-700">Barangay</label>
                        <input id="barangay" name="barangay" type="text" required
                               value="{{ old('barangay', $user->barangay) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('barangay')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="address" class="block text-sm font-medium text-slate-700">Address</label>
                        <input id="address" name="address" type="text" value="{{ old('address', $user->address) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('address')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @include('partials.unsaved-changes')

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
                @forelse ($eligibility as $category => $explanation)
                    <div class="rounded-lg bg-white/70 px-4 py-3">
                        <dt class="text-sm font-semibold text-slate-800">
                            {{ \App\Enums\PriorityType::from($category)->label() }}
                            <span class="ml-1 text-xs font-normal text-slate-500">
                                ({{ \App\Enums\PriorityType::from($category)->basis() }})
                            </span>
                        </dt>
                        <dd class="mt-1 text-sm text-slate-600">{{ $explanation }}</dd>
                    </div>
                @empty
                    <p class="rounded-lg bg-white/70 px-4 py-3 text-sm text-slate-600">
                        No priority categories are defined for this service type yet.
                    </p>
                @endforelse
            </dl>

            <p class="mt-4 rounded-lg border border-amber-200 bg-white px-4 py-3 text-sm">
                <span class="font-semibold text-amber-900">Current status:</span>
                @if ($user->meetsSeniorCitizenAge())
                    <span class="text-amber-800">You are recognised as a senior citizen and will be
                    prioritised automatically once your date of birth is confirmed at the counter.</span>
                @else
                    <span class="text-amber-800">No profile-based priority applies to you. You may still
                    claim PWD or pregnant priority per transaction when booking.</span>
                @endif
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="font-bold text-slate-900">Appointment notifications</h2>
            <p class="mt-1 text-sm text-slate-600">
                Choose how you want to hear about confirmations, reminders and changes to your
                appointments. In-app messages are always on, so you can always see what happened when
                you sign in.
            </p>

            <form method="POST" action="{{ route('client.profile.notifications') }}" data-track-dirty class="mt-4 space-y-3">
                @csrf
                @method('PUT')

                <label class="flex items-start gap-3 text-sm text-slate-800">
                    <input type="checkbox" name="notify_email" value="1"
                           @checked(old('notify_email', $user->wantsEmail()))
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                    <span>
                        <span class="font-medium">Email me</span>
                        <span class="block text-xs text-slate-500">Sent to {{ $user->email }}</span>
                    </span>
                </label>

{{-- The SMS toggle is only offered when SMS is actually configured.

                     Without this a client could tick "Text me", see it stored, and
                     then never receive a text - while the profile said messages
                     were on. The notification log showed why ("SMS is disabled
                     in configuration"), but a client does not read that screen.

                     The form input is gone too, not just the label, so a crafted
                     post cannot set a preference the system will not honour. --}}
                @if (config('govqueue.notifications.sms') && config('govqueue.notifications.enabled', true))
                    <label class="flex items-start gap-3 text-sm text-slate-800">
                        <input type="checkbox" name="notify_sms" value="1"
                               @checked(old('notify_sms', $user->notify_sms))
                               class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                        <span>
                            <span class="font-medium">Text me</span>
                            @if (filled($user->phone))
                                <span class="block text-xs text-slate-500">Sent to {{ $user->phone }}</span>
                            @else
                                <span class="block text-xs font-medium text-amber-700">
                                    Add a mobile number to your profile first.
                                </span>
                            @endif
                        </span>
                    </label>
                @else
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-700">Text messages are not available</p>
                        <p class="mt-1 text-xs text-slate-600">
                            The municipal office has not set up text messaging yet, so nothing
                            would be sent. Your email preference below is the one that applies.
                        </p>
                    </div>
                @endif

                <p class="rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">
                    Turning off both means you will only be told about changes when you sign in to
                    GovQueue. You may still miss your slot if you do not check.
                </p>

                @include('partials.unsaved-changes')

                <button type="submit"
                        class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Save notification settings
                </button>
            </form>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="font-bold text-slate-900">Change password</h2>

            <p class="mt-1 text-sm text-slate-600">
                You can also
                <a href="{{ route('password.request') }}" class="text-blue-700 hover:underline">
                    reset your password by email
                </a>
                if you cannot remember your current one.
            </p>

            <form method="POST" action="{{ route('client.profile.password') }}" data-track-dirty class="mt-4 space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="current_password" class="block text-sm font-medium text-slate-700">Current password</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    @error('current_password')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('password')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirm new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                </div>

                @include('partials.unsaved-changes')

                <button type="submit"
                        class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Change password
                </button>
            </form>
        </div>
    </div>
@endsection