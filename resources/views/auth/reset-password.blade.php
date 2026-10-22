@extends('layouts.app')

@section('title', 'Choose a New Password')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8">
            <h1 class="text-2xl font-bold text-slate-900">Choose a new password</h1>
            <p class="mt-1 text-sm text-slate-600">
                This link can only be used once. Signing in on other devices will be required again.
            </p>

            <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4"
                  x-data="{ loading: false }" @submit="loading = true">
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

                <button type="submit" :disabled="loading"
                        class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:opacity-60">
                    <span x-show="!loading">Change my password</span>
                    <span x-show="loading" class="flex items-center justify-center gap-2" aria-live="polite">
                        <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        Saving…
                    </span>
                </button>
            </form>
        </div>
    </div>
@endsection
