@extends('layouts.app')

@section('title', 'Staff Accounts')

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
            <li class="font-medium text-slate-900">Staff Accounts</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Staff accounts</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Staff and administrators only; clients manage their own details. Deactivating an account
                blocks sign-in but keeps every appointment it handled.
            </p>
        </div>
        <a href="{{ route('admin.staff.create') }}"
           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Add account
        </a>
    </div>

    @if ($activeAdminCount <= 1)
        <div role="alert"
             class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4">
            <p class="font-semibold text-amber-900">This is the only active administrator</p>
            <p class="mt-1 text-sm text-amber-800">
                Promote a second administrator before changing anything here. If the last administrator is
                deactivated or demoted, nobody could reach these screens or reset any password.
            </p>
        </div>
    @endif

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('admin.staff.index') }}"
          class="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div class="min-w-[12rem] flex-1">
            <label for="q" class="block text-xs font-medium text-slate-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Name or email"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
                          focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <div>
            <label for="role" class="block text-xs font-medium text-slate-700">Role</label>
            <select id="role" name="role"
                    class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                           focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                <option value="">All roles</option>
                <option value="staff" @selected(($filters['role'] ?? '') === 'staff')>Office Staff</option>
                <option value="admin" @selected(($filters['role'] ?? '') === 'admin')>Administrator</option>
            </select>
        </div>

        <button type="submit"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Filter
        </button>

        @if (array_filter($filters))
            <a href="{{ route('admin.staff.index') }}"
               class="px-2 py-2 text-sm text-indigo-600 hover:underline">Clear</a>
        @endif
    </form>

    {{-- Accounts table --}}
    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Staff and administrator accounts</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-2">Name</th>
                    <th scope="col" class="px-4 py-2">Role</th>
                    <th scope="col" class="px-4 py-2">Window</th>
                    <th scope="col" class="px-4 py-2">State</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($accounts as $account)
                    <tr @class(['align-top', 'opacity-60' => ! $account->is_active,
                                'hover:bg-slate-50/70' => true])>
                        <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                            {{ $account->name }}
                            @if ($account->id === auth()->id())
                                <span class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5
                                             text-xs font-medium text-indigo-700">You</span>
                            @endif
                            <span class="block text-xs font-normal text-slate-500">
                                {{ $account->email }}
                            </span>
                        </th>
                        <td class="px-4 py-3">{{ $account->role->label() }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $account->serviceWindow?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($account->is_active)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5
                                             text-xs font-medium text-emerald-800">Active</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5
                                             text-xs font-medium text-slate-600">Deactivated</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <a href="{{ route('admin.staff.edit', $account) }}"
                                   class="rounded-lg border border-slate-300 px-3 py-1.5
                                          text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Edit
                                </a>

                                @php
                                    // Mirrors StaffAdminService::toggleActive() exactly, so a
                                    // control that would fail on the server is not offered
                                    // in the first place.
                                    $isSelf          = $account->id === auth()->id();
                                    $cannotDeactivate = $account->is_active
                                        && $account->isAdmin()
                                        && ($isSelf || $activeAdminCount <= 1);
                                @endphp

                                <form method="POST" action="{{ route('admin.staff.toggle', $account) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit"
                                            @disabled($cannotDeactivate)
                                            title="{{ $cannotDeactivate
                                                ? ($isSelf
                                                    ? 'You cannot deactivate your own account.'
                                                    : 'This is the last active administrator. Promote or activate another administrator first.')
                                                : null }}"
                                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold
                                                   disabled:cursor-not-allowed disabled:opacity-50
                                                   {{ $account->is_active
                                                      ? 'border-amber-300 text-amber-800 hover:bg-amber-50'
                                                      : 'border-emerald-300 text-emerald-800 hover:bg-emerald-50' }}">
                                        {{ $account->is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                </form>

                                @unless ($account->id === auth()->id())
                                    <form method="POST" action="{{ route('admin.staff.destroy', $account) }}"
                                          onsubmit="return window.govqueueConfirm
                                              ? window.govqueueConfirm(this, 'Delete this staff account?',
                                                  {!! e(json_encode($account->name.' will no longer be able to sign in. Their booking history is kept, so reports and the audit trail stay complete.')) !!})
                                              : confirm('Delete the account for {{ addslashes($account->name) }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="rounded-lg border border-rose-300 px-3 py-1.5
                                                       text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                            Delete
                                        </button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke-width="1.5" stroke="currentColor"
                                 class="mx-auto mb-3 h-10 w-10 text-slate-300">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                            </svg>
                            <p class="text-sm font-medium text-slate-600">No accounts match.</p>
                            <p class="mt-1 text-xs text-slate-400">
                                <a href="{{ route('admin.staff.create') }}"
                                   class="text-indigo-600 hover:underline">Add a staff account</a>
                                to get started.
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
