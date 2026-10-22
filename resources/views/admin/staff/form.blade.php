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

    <form method="POST"
          action="{{ $account ? route('admin.staff.update', $account) : route('admin.staff.store') }}"
          class="mt-6 max-w-2xl space-y-5"
          data-track-dirty>
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

            @php
                // The backend already refuses both of these
                // (StaffAdminService::updateStaff). Disabled here so the
                // administrator is told before saving rather than after.
                $isSelf           = (bool) ($account && $account->id === auth()->id());
                $isLastActiveAdmin = (bool) ($account
                    && $account->isAdmin()
                    && $account->is_active
                    && $activeAdminCount <= 1);

                // Computed here rather than inline in the attribute: a
                // nested quoted string inside help="..." is more fragile
                // than it needs to be.
                $roleHelp = match (true) {
                    $isSelf           => 'You cannot change your own role.',
                    $isLastActiveAdmin => 'This is the last active administrator. Promote another account before changing this one.',
                    default            => 'Administrators can configure the system and open any window console.',
                };
            @endphp

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
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

            {{-- Off by default for an existing account: an administrator editing a
                 colleague's name should not silently put them back on a
                 forced password change. New accounts are always forced. --}}
            <div class="mt-4">
                <label class="flex items-start gap-3 text-sm text-slate-800">
                    <input type="checkbox" name="must_change_password" value="1"
                           @checked(old('must_change_password', ! $account || $account->must_change_password))
                           class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="font-medium">Must change password at next sign-in</span>
                        <span class="block text-xs text-slate-500">
                            Any password set here is a shared secret. Tick this and the account is sent
                            straight to the change-password screen instead of the dashboard.
                        </span>
                    </span>
                </label>
            </div>

            @if ($account)
                <div class="mt-5">
                    <label @class([
                        'flex items-start gap-3 text-sm text-slate-800',
                        'opacity-60' => $isSelf || $isLastActiveAdmin,
                    ])>
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $account->is_active))
                               @disabled($isSelf || $isLastActiveAdmin)
                               class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500
                                      disabled:cursor-not-allowed">
                        <span>
                            <span class="font-medium">Active</span>
                            <span class="block text-xs text-slate-500">
                                @if ($isSelf)
                                    You cannot deactivate your own account.
                                @elseif ($isLastActiveAdmin)
                                    This is the last active administrator. Promote another account first.
                                @else
                                    Unchecking blocks sign-in. The account keeps its history.
                                @endif
                            </span>
                        </span>
                    </label>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3">
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

            {{-- Unsaved changes banner --}}
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
