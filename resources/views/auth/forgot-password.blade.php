@extends('layouts.app')

@section('title', 'Reset Password')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8">
            <h1 class="text-2xl font-bold text-slate-900">Reset your password</h1>
            <p class="mt-1 text-sm text-slate-600">
                Enter the email address on your account and we will send you a link to choose a new password.
            </p>

            <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                    <input id="email" name="email" type="email" required autofocus autocomplete="email"
                           value="{{ old('email') }}"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    @error('email')
                        <p role="alert" class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">
                    Send reset link
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Remembered it?
                <a href="{{ route('login') }}" class="font-semibold text-blue-700 hover:underline">Back to sign in</a>
            </p>
        </div>

        <p class="mt-4 text-center text-xs text-slate-500">
            This system is a student project and is not an official government system. If you cannot
            reset your password, contact the municipal information desk.
        </p>
    </div>
@endsection
