@extends('layouts.app')

@section('title', 'Reset Password')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <h1 class="text-2xl font-bold text-slate-900">Reset your password</h1>
            <p class="mt-1 text-sm text-slate-600">
                Enter the email address on your account. If it is registered and active,
                we will send a link to choose a new password.
            </p>

            @if (session('status'))
                {{-- Success state: show prominently and hide the form to prevent re-submission --}}
                <div role="status" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm text-emerald-800">{{ session('status') }}</p>
                    </div>
                </div>
                <p class="mt-4 text-center text-sm text-slate-600">
                    <a href="{{ route('login') }}" class="font-semibold text-blue-700 hover:underline">Back to sign in</a>
                </p>
            @else
                <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4"
                      x-data="{ loading: false }" @submit="loading = true">
                    @csrf

                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                        <input id="email" name="email" type="email" required autofocus autocomplete="email"
                               value="{{ old('email') }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500
                                      @error('email') border-rose-400 focus:border-rose-400 focus:ring-rose-400 @enderror">
                        @error('email')
                            <p role="alert" class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500">
                            The same address you used when you registered.
                        </p>
                    </div>

                    <button type="submit" :disabled="loading"
                            class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:opacity-60">
                        <span x-show="!loading">Send reset link</span>
                        <span x-show="loading" class="flex items-center justify-center gap-2" aria-live="polite">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                            </svg>
                            Sending…
                        </span>
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-slate-600">
                    Remembered it?
                    <a href="{{ route('login') }}" class="font-semibold text-blue-700 hover:underline">Back to sign in</a>
                </p>
            @endif
        </div>

        <p class="mt-4 text-center text-xs text-slate-500">
            This system is a student project and is not an official government system. If you cannot
            reset your password, contact the municipal information desk.
        </p>
    </div>
@endsection
