<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\AdminActionBlockedException;
use App\Models\AuditLog;
use App\Models\ServiceWindow;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Administrator-managed staff and administrator accounts.
 *
 * The rules that matter here are all about not locking the office out of its own
 * system. An administrator who can deactivate themselves, or demote the only
 * other administrator, can leave a deployment where nobody can reach the
 * configuration screens or the staff console - and the only way back in would be
 * direct database surgery.
 */
class StaffAdminService
{
    public function createStaff(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed by the model's cast
            // A new account is created with a password the administrator chose
            // and shared by hand. Until the holder replaces it, that password
            // is as good as public, so they are forced to change it at sign-in
            // rather than being asked nicely to remember to.
            'must_change_password' => (bool) ($data['must_change_password'] ?? true),
            'role' => $data['role'],
            'phone' => $data['phone'] ?? null,
            'service_window_id' => $this->resolveWindowId($data),
            'is_active' => true,
        ]);

        AuditLog::record('user.created', $user, [
            'email' => $user->email,
            'role' => $user->role->value,
            'service_window_id' => $user->service_window_id,
        ]);

        return $user;
    }

    public function updateStaff(User $user, array $data): User
    {
        $before = $user->only(['name', 'email', 'role', 'is_active', 'service_window_id']);

        /*
         * Three ways an edit could remove the last usable administrator:
         * demoting one, deactivating one, or deleting one. All are checked here
         * against the *result* of the edit rather than against a flag, so a
         * single request that both demotes and reactivates is judged correctly.
         */
        $resultingRole = $data['role'] ?? $user->role->value;
        $resultingActive = $data['is_active'] ?? $user->is_active;

        $losesAdmin = $user->isAdmin() && ($resultingRole !== UserRole::Admin->value || ! $resultingActive);

        if ($losesAdmin && $this->activeAdminCount() <= 1) {
            $this->block('user.change_blocked', $user, [
                'attempted_role' => $resultingRole,
                'attempted_active' => $resultingActive,
                'reason' => 'last_administrator',
            ], AdminActionBlockedException::lastAdmin($user->name));
        }

        $attributes = [
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'role' => $resultingRole,
            'is_active' => $resultingActive,
            'service_window_id' => array_key_exists('service_window_id', $data)
                ? $this->resolveWindowId($data)
                : $user->service_window_id,
        ];

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];

            // A password typed into the edit form is a new shared secret too, so
            // it carries the same requirement as a fresh account.
            $attributes['must_change_password'] = true;
        }

        if (array_key_exists('must_change_password', $data)) {
            $attributes['must_change_password'] = (bool) $data['must_change_password'];
        }

        $user->update($attributes);

        AuditLog::record('user.updated', $user, [
            'before' => $before,
            'after' => $user->only(['name', 'email', 'role', 'is_active', 'service_window_id']),
            'password_reset' => ! empty($data['password']),
        ]);

        return $user;
    }

    public function toggleActive(User $user): User
    {
        // A deactivated administrator cannot sign in, so self-deactivation is a
        // lockout; and if they are the last one it strands the deployment.
        if ($user->is_active && $user->isAdmin()) {
            if ($user->id === auth()->id()) {
                $this->block('user.change_blocked', $user, ['reason' => 'self_deactivation'], AdminActionBlockedException::cannotChangeYourOwnAccount('deactivate'));
            }

            if ($this->activeAdminCount() <= 1) {
                $this->block('user.change_blocked', $user, ['reason' => 'last_administrator'], AdminActionBlockedException::lastAdmin($user->name));
            }
        }

        $user->update(['is_active' => ! $user->is_active]);

        AuditLog::record($user->is_active ? 'user.activated' : 'user.deactivated', $user, [
            'email' => $user->email,
        ]);

        return $user;
    }

    /**
     * Staff are deactivated, never deleted.
     *
     * appointments.handled_by and priority_verified_by reference the user, and so
     * do audit_logs and transactions. Deleting would either fail on the
     * constraint or blank out the record of who actually served a client.
     */
    public function deleteStaff(User $user): void
    {
        if ($user->id === auth()->id()) {
            $this->block('user.change_blocked', $user, ['reason' => 'self_deletion'], AdminActionBlockedException::cannotChangeYourOwnAccount('delete'));
        }

        if ($user->isAdmin() && $this->activeAdminCount() <= 1) {
            $this->block('user.change_blocked', $user, ['reason' => 'last_administrator'], AdminActionBlockedException::lastAdmin($user->name));
        }

        AuditLog::record('user.deleted', $user, ['email' => $user->email, 'role' => $user->role->value]);

        $user->delete();
    }

    public function resetPassword(User $user, string $password): void
    {
        if ($user->id === auth()->id()) {
            $this->block('user.change_blocked', $user, ['reason' => 'self_password_reset'], AdminActionBlockedException::cannotChangeYourOwnAccount('reset the password of'));
        }

        // An administrator-issued password is a shared secret, so the account must
        // replace it at their next sign-in. Clearing the flag here would leave a
        // password the administrator knows working indefinitely.
        $user->update([
            'password' => $password, // hashed by the model's cast
            'must_change_password' => true,
        ]);

        AuditLog::record('user.password_reset', $user, [
            'email' => $user->email,
            'must_change_password' => true,
        ]);
    }

    /**
     * Refuse an account change and record the attempt.
     *
     * Repeated attempts to demote or disable the last administrator are exactly
     * the pattern an audit trail exists to reveal, so a refusal is logged too -
     * otherwise the trail only ever shows what succeeded.
     *
     * @param  array<string, mixed>  $payload
     */
    private function block(string $action, User $subject, array $payload, AdminActionBlockedException $exception): never
    {
        AuditLog::record($action, $subject, [
            ...$payload,
            'email' => $subject->email,
            'blocked_reason' => $exception->getMessage(),
        ]);

        throw $exception;
    }

    /**
     * Clients may hold a slot in a window; staff and administrators must not.
     */
    private function resolveWindowId(array $data): ?int
    {
        if (($data['role'] ?? null) === UserRole::Client->value) {
            return null;
        }

        $windowId = $data['service_window_id'] ?? null;

        return blank($windowId) ? null : (int) $windowId;
    }

    private function activeAdminCount(): int
    {
        return User::where('role', UserRole::Admin->value)->where('is_active', true)->count();
    }

    /**
     * Whether at least one active administrator would remain if this user were
     * changed. Used to drive a warning in the UI rather than only at submit time.
     */
    public function wouldLeaveNoAdministrator(User $user, ?string $newRole, ?bool $newActive): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        $resultingRole = $newRole ?? $user->role->value;
        $resultingActive = $newActive ?? $user->is_active;

        if ($resultingRole === UserRole::Admin->value && $resultingActive) {
            return false;
        }

        return $this->activeAdminCount() <= 1;
    }

    /**
     * Windows an account may be assigned to. Inactive windows are excluded so a
     * staff member cannot be parked on a counter that is not being worked.
     */
    public function assignableWindows(): Collection
    {
        return ServiceWindow::where('is_active', true)->orderBy('name')->get();
    }
}
