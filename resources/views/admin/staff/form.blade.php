@extends('layouts.app')

@section('title', $account ? 'Edit Account' : 'Add Account')

@section('content')
    {{-- Breadcrumb --}}
    <nav aria-label="Breadcrumb" class="mb-4">
        <ol class="flex flex-wrap items-center gap-x-1 gap-y-1 text-sm text-slate-500">
            <li>
                <a href="{{ route('admin.dashboard') }}"
                   class="hover:text-slate-700 hover:underline">Dashboard</a>
            </li>
            <li aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 text-slate-400">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                </svg>
            </li>
            <li>
                <a href="{{ route('admin.staff.index') }}"
                   class="hover:text-slate-700 hover:underline">Staff Accounts</a>
            </li>
            <li aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 text-slate-400">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                </svg>
            </li>
            <li class="font-medium text-slate-900">{{ $account ? 'Edit' : 'New' }}</li>
        </ol>
    </nav>

    <h1 class="text-2xl font-bold text-slate-900">
        {{ $account ? 'Edit '.$account->name : 'Add a staff account' }}
    </h1>
    @if ($account)
        <p class="mt-1 text-sm text-slate-500">
            Created {{ $account->created_at->diffForHumans() }}
            @if ($account->must_change_password)
                &middot;
                <span class="font-medium text-amber-600">Must reset password on next sign-in</span>
            @endif
        </p>
    @endif

    <form method="POST"
          action="{{ $account ? route('admin.staff.update', $account) : route('admin.staff.store') }}"
          class="mt-6 max-w-2xl space-y-5"
          data-track-dirty>
        @csrf
        @if ($account)
            @method('PUT')
        @endif

        {{-- ── Card 1: Identity ─────────────────────────────────────────────────── --}}
        <section aria-labelledby="identity-heading">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="identity-heading"
                        class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="h-3.5 w-3.5">
                            <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM12.735 14c.618 0 1.093-.561.872-1.139a6.002 6.002 0 0 0-11.215 0c-.22.578.254 1.139.872 1.139h9.47Z"/>
                        </svg>
                        Identity
                    </h2>
                </div>
                <div class="space-y-5 p-5">
                    <x-admin.field name="name" label="Full name" required
                                  :value="$account?->name" placeholder="Juan Dela Cruz" />

                    <x-admin.field name="email" type="email" label="Email address" required
                                  :value="$account?->email" placeholder="name@govqueue.test"
                                  help="This is also the sign-in username." />

                    <x-admin.field name="phone" label="Phone" :value="$account?->phone"
                                  placeholder="09XXXXXXXXX" />
                </div>
            </div>
        </section>

        {{-- ── Card 2: Role & Assignment ─────────────────────────────────────────── --}}
        <section aria-labelledby="role-heading">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="role-heading"
                        class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="h-3.5 w-3.5">
                            <path fill-rule="evenodd" d="M7.5 1.709a.75.75 0 0 1 1 0l5.5 5.157a.75.75 0 0 1-.5 1.291H12.5V13a.75.75 0 0 1-.75.75h-3V10.5a.75.75 0 0 0-.75-.75h-2a.75.75 0 0 0-.75.75v3.25h-3A.75.75 0 0 1 1.5 13V8.157H.5a.75.75 0 0 1-.5-1.291l7.5-5.157Z" clip-rule="evenodd"/>
                        </svg>
                        Role &amp; Assignment
                    </h2>
                </div>
                <div class="space-y-5 p-5">
                    @php
                        $isSelf            = (bool) ($account && $account->id === auth()->id());
                        $isLastActiveAdmin = (bool) ($account
                            && $account->isAdmin()
                            && $account->is_active
                            && $activeAdminCount <= 1);

                        $roleHelp = match (true) {
                            $isSelf            => 'You cannot change your own role.',
                            $isLastActiveAdmin => 'This is the last active administrator. Promote another account before changing this one.',
                            default            => 'Administrators can configure the system and open any window console.',
                        };
                    @endphp

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.field name="role" type="select" label="Role" required
                                      :disabled="$isSelf || $isLastActiveAdmin"
                                      :help="$roleHelp">
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

                    @if ($account && $account->isStaff())
                        <p class="text-xs text-slate-500">
                            Assigning a window lets the staff member open that window's console directly.
                            Administrators can open any window regardless of this setting.
                        </p>
                    @endif
                </div>
            </div>
        </section>

        {{-- ── Card 3: Security ─────────────────────────────────────────────────── --}}
        <section aria-labelledby="security-heading">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="security-heading"
                        class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                             class="h-3.5 w-3.5">
                            <path fill-rule="evenodd" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V7A1.5 1.5 0 0 0 3 8.5v5A1.5 1.5 0 0 0 4.5 15h7a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 11 7V4.5A3.5 3.5 0 0 0 8 1Zm2 6V4.5a2 2 0 1 0-4 0V7h4Z" clip-rule="evenodd"/>
                        </svg>
                        {{ $account ? 'Reset password' : 'Temporary password' }}
                    </h2>
                </div>
                <div class="p-5">
                    @if ($account)
                        <p class="mb-4 text-xs text-slate-500">
                            Leave both boxes empty to keep the current password.
                        </p>
                    @else
                        <p class="mb-4 text-xs text-slate-500">
                            At least 8 characters. Share it securely and ask them to change it at first sign-in.
                        </p>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-admin.field name="password" type="password" label="Password"
                                      :required="! $account" autocomplete="new-password" />

                        <x-admin.field name="password_confirmation" type="password" label="Confirm password"
                                      :required="! $account" autocomplete="new-password" />
                    </div>

                    {{-- Must-change-password flag --}}
                    <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="must_change_password" value="1"
                                   @checked(old('must_change_password', ! $account || $account->must_change_password))
                                   class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-slate-800">
                                <span class="font-medium">Must change password at next sign-in</span>
                                <span class="mt-0.5 block text-xs text-slate-500">
                                    Any password set here is a shared secret. Tick this and the account is sent
                                    straight to the change-password screen instead of the dashboard.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Account status (edit only) ───────────────────────────────────────── --}}
        @if ($account)
            <section aria-labelledby="status-heading">
                <div class="rounded-xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h2 id="status-heading"
                            class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-slate-500">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                 class="h-3.5 w-3.5">
                                <path fill-rule="evenodd" d="M15 8A7 7 0 1 1 1 8a7 7 0 0 1 14 0ZM9 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM6.75 8a.75.75 0 0 0 0 1.5h.75v1.75a.75.75 0 0 0 1.5 0V8.75A.75.75 0 0 0 8.25 8h-1.5Z" clip-rule="evenodd"/>
                            </svg>
                            Account status
                        </h2>
                    </div>
                    <div class="p-5">
                        <div @class([
                            'rounded-lg border p-4',
                            'border-slate-200 bg-slate-50'  =>  ! ($isSelf || $isLastActiveAdmin),
                            'border-amber-200 bg-amber-50'  =>  $isSelf || $isLastActiveAdmin,
                            'opacity-60'                    =>  $isSelf || $isLastActiveAdmin,
                        ])>
                            <label class="flex items-start gap-3">
                                <input type="checkbox" name="is_active" value="1"
                                       @checked(old('is_active', $account->is_active))
                                       @disabled($isSelf || $isLastActiveAdmin)
                                       class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500
                                              disabled:cursor-not-allowed">
                                <span class="text-sm text-slate-800">
                                    <span class="font-medium">Active</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">
                                        @if ($isSelf)
                                            You cannot deactivate your own account.
                                        @elseif ($isLastActiveAdmin)
                                            This is the last active administrator. Promote another account first.
                                        @else
                                            Unchecking blocks sign-in immediately. The account keeps all its history.
                                        @endif
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- ── Actions ──────────────────────────────────────────────────────────── --}}
        <div class="flex items-center gap-3 pb-4">
            <button type="submit"
                    data-unsaved
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white
                           hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                {{ $account ? 'Save changes' : 'Create account' }}
            </button>
            <a href="{{ route('admin.staff.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold
                      text-slate-700 hover:bg-slate-50">
                Cancel
            </a>

            {{-- Unsaved changes indicator --}}
            <span data-unsaved
                  class="ml-2 flex items-center gap-1.5 text-xs font-medium text-amber-600 opacity-0 transition-opacity duration-200">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5">
                    <path fill-rule="evenodd" d="M6.701 2.25c.577-1 2.02-1 2.598 0l5.196 9a1.5 1.5 0 0 1-1.299 2.25H2.804a1.5 1.5 0 0 1-1.3-2.25l5.197-9ZM8 4a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-1.5 0v-3A.75.75 0 0 1 8 4Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                </svg>
                Unsaved changes
            </span>
        </div>
    </form>
@endsection
