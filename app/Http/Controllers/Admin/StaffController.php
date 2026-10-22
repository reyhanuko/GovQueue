<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Exceptions\AdminActionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OpsNotificationService;
use App\Services\StaffAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff and administrator account management.
 *
 * Clients are not managed here: a client registers themselves and edits their own
 * profile. This screen only covers the accounts that operate the system.
 */
class StaffController extends Controller
{
    public function __construct(
        private StaffAdminService $admin,
        private OpsNotificationService $ops,
    ) {}

    public function index(Request $request): View
    {
        $accounts = User::query()
            ->with('serviceWindow')
            ->whereIn('role', [UserRole::Staff->value, UserRole::Admin->value])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')->toString()))
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('is_active', $request->input('status') === 'active');
            })
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return view('admin.staff.index', [
            'accounts'         => $accounts,
            'windows'          => $this->admin->assignableWindows(),
            'filters'          => $request->only(['q', 'role', 'status']),
            'activeAdminCount' => User::where('role', UserRole::Admin->value)->where('is_active', true)->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.staff.form', [
            'account' => null,
            'windows' => $this->admin->assignableWindows(),
            // The form disables self-demotion and last-admin controls; it needs
            // the same count the service uses so the two cannot disagree.
            'activeAdminCount' => User::where('role', UserRole::Admin->value)->where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, requirePassword: true);

        $user = $this->admin->createStaff($data);

        return redirect()->route('admin.staff.index')
            ->with('success', "Account created for {$user->name}. Share the temporary password securely.");
    }

    public function edit(User $user): View
    {
        $user->load('serviceWindow');

        return view('admin.staff.form', [
            'account' => $user,
            'windows' => $this->admin->assignableWindows(),
            'activeAdminCount' => User::where('role', UserRole::Admin->value)->where('is_active', true)->count(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // The password is optional on edit: blank means "leave it alone", which
        // is different from setting a blank password.
        $data = $this->validated($request, requirePassword: false);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        /*
         * Captured before the update, because after it the model carries the new
         * window and there would be nothing left to compare against. Only
         * staff have a window, so an administrator's edit never triggers the
         * "you have been moved" message.
         */
        $previousWindow = $user->isStaff() ? $user->serviceWindow?->name : null;

        try {
            $this->admin->updateStaff($user, $data);
        } catch (AdminActionBlockedException $e) {
            return back()->withInput()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        $newWindow = $user->fresh()->serviceWindow?->name;

        if ($user->isStaff() && $previousWindow !== $newWindow) {
            $this->ops->windowAssignmentChanged($user, $previousWindow, $newWindow);
        }

        return redirect()->route('admin.staff.index')
            ->with('success', "Account for {$user->name} updated.");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        try {
            $this->admin->toggleActive($user);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        /*
         * Told to the person affected, not just to the administrator who did it.
         * A clerk whose account has been switched off needs to know before they
         * try to sign in for their next shift, and the `active` middleware will
         * refuse them with no explanation.
         */
        if ($user->isStaff() && ! $user->fresh()->is_active) {
            $this->ops->accountDeactivated($user);
        }

        return back()->with('success', $user->fresh()->is_active
            ? "{$user->name} can sign in again."
            : "{$user->name} can no longer sign in. Their history is kept.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $this->admin->resetPassword($user, $data['password']);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        if ($user->isStaff()) {
            $this->ops->passwordWasReset($user);
        }

        return back()->with('success', "Password reset for {$user->name}.");
    }

    public function destroy(User $user): RedirectResponse
    {
        $name = $user->name;

        try {
            $this->admin->deleteStaff($user);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        return back()->with('success', "Account for {$name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requirePassword): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'role' => ['required', 'in:staff,admin'],
            'phone' => ['nullable', 'string', 'max:24'],
            'service_window_id' => ['nullable', 'integer', 'exists:service_windows,id'],
            'password' => $requirePassword
                ? ['required', 'string', 'min:8', 'confirmed']
                : ['nullable', 'string', 'min:8', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
            'must_change_password' => ['nullable', 'boolean'],
        ];

        if (! $requirePassword) {
            /*
             * Uniqueness must exclude the account being edited, or saving an
             * unchanged email address fails against the account's own row.
             *
             * The route parameter is named "user" (it matches the type-hinted
             * argument), so this reads the bound model, not a literal id.
             */
            $current = $request->route('user');

            $rules['email'][] = 'unique:users,email,'.($current instanceof User ? $current->id : 'NULL');
        } else {
            $rules['email'][] = 'unique:users,email';
        }

        $data = $request->validate($rules, [
            'email.unique' => 'Another account already uses that email address.',
            'password.confirmed' => 'The two passwords do not match.',
            'password.min' => 'Use at least 8 characters.',
        ]);

        // Unchecked checkboxes are absent from the payload.
        $data['is_active'] = $request->boolean('is_active');

        // Only set when the form actually sent the field. A brand new account
        // defaults to "must change" in StaffAdminService, and forcing the value
        // to false here whenever the box is absent would quietly cancel that -
        // which is the case for anyone posting the form without the checkbox.
        if ($request->has('must_change_password')) {
            $data['must_change_password'] = $request->boolean('must_change_password');
        }

        if (blank($data['service_window_id'] ?? null)) {
            $data['service_window_id'] = null;
        }

        return $data;
    }
}
