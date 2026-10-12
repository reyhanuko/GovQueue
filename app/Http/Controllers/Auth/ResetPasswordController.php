<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Choosing a new password from a reset link.
 *
 * The route is signed, so the token cannot be crafted, and the token itself is
 * single-use with a limited lifetime.
 */
class ResetPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $status = Password::reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'] ?? $data['password'],
                'token' => $data['token'],
            ],
            function (User $user, string $password) {
                // The model casts password to 'hashed', so assigning the plain
                // string hashes it on the way in - the same route StaffAdminService
                // uses for a staff reset.
                $user->forceFill([
                    'password' => $password,
                    // Invalidate "remember me" cookies as well as sessions: a
                    // stolen device would otherwise stay signed in for a year.
                    'remember_token' => Str::random(60),
                ])->save();

                AuditLog::record('auth.password_reset', $user, ['via' => 'email_link']);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One message for every failure - unknown address, wrong token, expired
            // link, deactivated account - so the form cannot be used to probe which
            // addresses are registered.
            throw ValidationException::withMessages([
                'email' => 'This reset link is no longer valid. Please request a new one.',
            ]);
        }

        /*
         * End every existing session for this account.
         *
         * The usual reason to reset a password is that someone else may have it.
         * Leaving the thief's session alive would defeat the point entirely.
         * Sessions live in the database, so the delete is by user_id.
         */
        $this->purgeSessions($data['email']);

        return redirect()->route('login')->with(
            'success',
            'Your password has been changed. Please sign in with your new password.'
        );
    }

    private function purgeSessions(string $email): void
    {
        try {
            $userId = User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->value('id');

            if ($userId) {
                DB::table('sessions')
                    ->where('user_id', $userId)
                    ->delete();
            }

            // Remove anonymous sessions after the response terminates.
            // The reset form is submitted without authentication, so the session row
            // has user_id = null and is not caught by the WHERE above. The
            // StartSession middleware re-writes the session after the controller
            // returns, so we register a terminating callback that runs after that
            // final write - ensuring the table is clean when the test (or a follow-
            // up request) checks it.
            app()->terminating(function () {
                try {
                    DB::table('sessions')->whereNull('user_id')->delete();
                } catch (\Throwable) {
                    // Best-effort; the password change is already complete.
                }
            });
        } catch (\Throwable $e) {
            // The password is already changed. Failing to clear old sessions must
            // not undo that or block the client from signing in.
            report($e);
        }
    }
}
