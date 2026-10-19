@extends('layouts.app')

@section('title', 'Choose Your Own Password')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8">
            <h1 class="text-2xl font-bold text-slate-900">Choose your own password</h1>

            @if ($required)
                <p class="mt-1 text-sm text-slate-600">
                    Your account is still on the temporary password an administrator gave you.
                    Choose your own before you carry on &mdash; that password was shared in writing and
                    should not stay in use.
                </p>
            @else
                <p class="mt-1 text-sm text-slate-600">
                    You can change your password here at any time. This is the same form used when an
                    account is first issued.
                </p>
            @endif

            <form method="POST" action="{{ route('auth.password.change.store') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="current_password" class="block text-sm font-medium text-slate-700">
                        {{ $required ? 'Temporary password' : 'Current password' }}
                    </label>
                    <input id="current_password" name="current_password" type="password" required autofocus
                           autocomplete="current-password"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    @error('current_password')
                        <p role="alert" class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
                    <input id="password" name="password" type="password" required
                           autocomplete="new-password" aria-describedby="password-help"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    <p id="password-help" class="mt-1 text-xs text-slate-500">
                        At least 8 characters, and not the same as the one you are signing in with.
                    </p>
                    @error('password')
                        <p role="alert" class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700">
                        Confirm new password
                    </label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    @error('password_confirmation')
                        <p role="alert" class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">
                    Save and continue
                </button>
            </form>

            @unless ($required)
                <p class="mt-4 text-center text-sm text-slate-600">
                    <x-user-home-link prefix="Back to" class="font-semibold text-blue-700 hover:underline" />
                </p>
            @endunless
        </div>
    </div>
@endsection