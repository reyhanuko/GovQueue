@extends('layouts.app')

@section('title', $account ? 'Edit Account' : 'Add Account')

@section('content')
    @include('admin.partials.nav')

    <nav class="text-sm" aria-label="Breadcrumb">
        <a href="{{ route('admin.staff.index') }}" class="text-blue-700 hover:underline">Staff accounts</a>
        <span class="mx-1 text-slate-400">/</span>
        <span class="text-slate-600">{{ $account ? 'Edit' : 'New' }}</span>
    </nav>

    <h1 class="mt-4 text-2xl font-bold text-slate-900">
        {{ $account ? 'Edit '.$account->name : 'Add a staff account' }}
    </h1>

    <form method="POST"
          action="{{ $account ? route('admin.staff.update', $account) : route('admin.staff.store') }}"
          class="mt-6 max-w-2xl space-y-5">
        @csrf
        @if ($account)
            @method('PUT')
        @endif

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <x-admin.field name="name" label="Full name" required
                          :value="$account?->name" placeholder="Juan Dela Cruz" />

            <div class="mt-5">
                <x-admin.field name="email" type="email" label="Email address" required
                              :value="$account?->email" placeholder="name@govqueue.test"
                              help="This is also the sign-in username." />
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-admin.field name="role" type="select" label="Role" required
                              help="Administrators can configure the system and open any window's console.">
                    @foreach (\App\Enums\UserRole::cases() as $role)
                        @continue($role === \App\Enums\UserRole::Client)
                        <option value="{{ $role->value }}"
                                @selected(old('role', $account?->role->value ?? 'staff') === $role->value)>
                            {{ $role->label() }}
                        </option>
                    @endforeach
                </x-admin.field>

                <x-admin.field name="service_window_id" type="select" label="Assigned window">
                    <option value="">No specific window</option>
                    @forelse ($windows as $window)
                        <option value="{{ $window->id }}"
                                @selected((int) old('service_window_id', $account?->service_window_id) === (int) $window->id)>
                            {{ $window->name }}
                        </option>
                    @empty
                        <option value="" disabled>No windows configured yet</option>
                    @endforelse
                </x-admin.field>
            </div>

            <div class="mt-5">
                <x-admin.field name="phone" label="Phone" :value="$account?->phone"
                              placeholder="09XXXXXXXXX" />
            </div>

            <fieldset class="mt-5 border-t border-slate-100 pt-5">
                <legend class="text-sm font-medium text-slate-700">
                    {{ $account ? 'Reset password' : 'Temporary password' }}
                </legend>

                @if ($account)
                    <p class="mt-1 text-xs text-slate-500">
                        Leave both boxes empty to keep the current password.
                    </p>
                @else
                    <p class="mt-1 text-xs text-slate-500">
                        At least 8 characters. Share it securely and ask them to change it.
                    </p>
                @endif

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <x-admin.field name="password" type="password" label="Password"
                                  :required="! $account" autocomplete="new-password" />

                    <x-admin.field name="password_confirmation" type="password" label="Confirm password"
                                  :required="! $account" autocomplete="new-password" />
                </div>
            </fieldset>

            @if ($account)
                <div class="mt-5">
                    <label class="flex items-start gap-3 text-sm text-slate-800">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $account->is_active))
                               class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500">
                        <span>
                            <span class="font-medium">Active</span>
                            <span class="block text-xs text-slate-500">
                                Unchecking blocks sign-in. The account keeps its history.
                            </span>
                        </span>
                    </label>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                {{ $account ? 'Save changes' : 'Create account' }}
            </button>
            <a href="{{ route('admin.staff.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Cancel
            </a>
        </div>
    </form>
@endsection
