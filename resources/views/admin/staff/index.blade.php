@extends('layouts.app')

@section('title', 'Staff Accounts')

@section('content')
    @include('admin.partials.nav')

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Staff accounts</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Staff and administrators only; clients manage their own details. Deactivating an account
                blocks sign-in but keeps every appointment it handled.
            </p>
        </div>
        <a href="{{ route('admin.staff.create') }}"
           class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
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

    <form method="GET" action="{{ route('admin.staff.index') }}"
          class="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div class="min-w-[12rem] flex-1">
            <label for="q" class="block text-xs font-medium text-slate-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Name or email"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="role" class="block text-xs font-medium text-slate-700">Role</label>
            <select id="role" name="role"
                    class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                <option value="">All roles</option>
                <option value="staff" @selected(($filters['role'] ?? '') === 'staff')>Office Staff</option>
                <option value="admin" @selected(($filters['role'] ?? '') === 'admin')>Administrator</option>
            </select>
        </div>

        <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">
            Filter
        </button>

        @if (array_filter($filters))
            <a href="{{ route('admin.staff.index') }}" class="px-2 py-2 text-sm text-blue-700 hover:underline">Clear</a>
        @endif
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <caption class="sr-only">Staff and administrator accounts</caption>
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
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
                    <tr @class(['opacity-60' => ! $account->is_active])>
                        <th scope="row" class="px-4 py-3 text-left font-medium text-slate-800">
                            {{ $account->name }}
                            @if ($account->id === auth()->id())
                                <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">You</span>
                            @endif
                            <span class="block text-xs font-normal text-slate-500">{{ $account->email }}</span>
                        </th>
                        <td class="px-4 py-3">{{ $account->role->label() }}</td>
                        <td class="px-4 py-3">{{ $account->serviceWindow?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($account->is_active)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Deactivated</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <a href="{{ route('admin.staff.edit', $account) }}"
                                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Edit
                                </a>

                                <form method="POST" action="{{ route('admin.staff.toggle', $account) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit"
                                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold
                                                   {{ $account->is_active
                                                      ? 'border-amber-300 text-amber-800 hover:bg-amber-50'
                                                      : 'border-emerald-300 text-emerald-800 hover:bg-emerald-50' }}">
                                        {{ $account->is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                </form>

                                @unless ($account->id === auth()->id())
                                    <form method="POST" action="{{ route('admin.staff.destroy', $account) }}"
                                          onsubmit="return confirm('Delete the account for {{ $account->name }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                            Delete
                                        </button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            No accounts match.
                            <a href="{{ route('admin.staff.create') }}" class="text-blue-700 underline">Add one</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
