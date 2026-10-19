@extends('layouts.app')

@section('title', 'Sign In')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8">
            <h1 class="text-2xl font-bold text-slate-900">Sign in to GovQueue</h1>
            <p class="mt-1 text-sm text-slate-600">Use the account you registered with, or your staff credentials.</p>

            <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                    <input id="email" name="email" type="email" required autofocus autocomplete="email"
                           value="{{ old('email') }}"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    @error('email')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    @error('password')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- py-1 so the row is a target of its own: at text-sm it is
                     20 pixels tall, and the label around the box is what a
                     finger lands on. --}}
                <label class="flex items-center gap-2 py-1 text-sm text-slate-600">
                    <input type="checkbox" name="remember" value="1"
                           class="rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                    Keep me signed in on this device
                </label>

                <button type="submit"
                        class="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">
                    Sign in
                </button>

                <p class="text-center text-sm">
                    <a href="{{ route('password.request') }}" class="font-semibold text-blue-700 hover:underline">
                        Forgot your password?
                    </a>
                </p>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                New resident of Tupi?
                <a href="{{ route('register') }}" class="font-semibold text-blue-700 hover:underline">Create an account</a>
            </p>
        </div>
    </div>
@endsection