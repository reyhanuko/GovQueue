<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The forced password change a new staff or administrator account must make
 * before it can be used.
 *
 * Separate from password reset because the account is already authenticated:
 * nobody emailed anything, and the session is valid. What is being replaced is
 * a password an administrator typed and shared by hand.
 */
class ChangePasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.change-password', [
            // Whether the change is compulsory. Reachable directly, but the
            // screen then says plainly that it is not urgent.
            'required' => (bool) $request->user()?->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'password.different' => 'Choose a password different from the one you signed in with.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            // Same treatment as a wrong sign-in password: say the password is
            // wrong, not which field.
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'], // hashed by the model's cast
            'must_change_password' => false,
        ])->save();

        // Rotate the session so a copy of the old cookie cannot be replayed
        // after the credential behind it has changed.
        $request->session()->regenerate();

        AuditLog::record('user.password_changed', $user, [
            'email' => $user->email,
            'forced' => true,
        ]);

        return redirect()
            ->route('dashboard')
            ->with('success', 'Your password has been changed.');
    }
}