@extends('layouts.app')

@section('title', 'Choose a New Password')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8">
            <h1 class="text-2xl font-bold text-slate-900">Choose a new password</h1>
            <p class="mt-1 text-sm text-slate-600">
                This link can only be used once. Signing in on other devices will be required again.
            </p>

            <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                    <input id="email" name="email" type="email" required autocomplete="email"
                           value="{{ old('email', $email) }}"
                           readonly
                           class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    @error('email')
                        <p role="alert" class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
                    <input id="password" name="password" type="password" required autofocus
                           autocomplete="new-password"
                           aria-describedby="password-help"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    <p id="password-help" class="mt-1 text-xs text-slate-500">
                        At least 8 characters.
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
                </div>

                <button type="submit"
                        class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">
                    Change my password
                </button>
            </form>
        </div>
    </div>
@endsection
