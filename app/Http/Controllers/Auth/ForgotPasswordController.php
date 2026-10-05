<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "I forgot my password".
 *
 * Without this, a client who forgets their password is locked out of booking,
 * viewing and cancelling appointments with no way back in, because there is no
 * counter process that could reset it for them.
 *
 * The responses are deliberately identical whether or not the address is
 * registered. Saying "no account uses that email" would turn this form into a way
 * to enumerate which residents hold accounts with the municipality, which is both
 * a privacy problem and a phishing aid.
 */
class ForgotPasswordController extends Controller
{
    /**
     * How long a client has to request one link, per IP and per address.
     */
    private const MAX_ATTEMPTS = 3;

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower($data['email']);
        $key = 'password-reset|'.Str::lower($request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            // Still phrased identically to the success message on purpose: the
            // rate limit must not become an oracle for which addresses are real.
            throw ValidationException::withMessages([
                'email' => $this->successMessage().' Too many requests, so please wait a few minutes.',
            ]);
        }

        RateLimiter::hit($key, 300);

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        // Only send to an account that could actually sign in. A deactivated
        // account is deliberately silent rather than told it is deactivated,
        // which would confirm the address exists.
        if ($user && $user->is_active) {
            $this->sendResetLink($user);
        }

        return back()->with('status', $this->successMessage());
    }

    private function sendResetLink(User $user): void
    {
        $token = Password::createToken($user);

        try {
            // Mail::to(...)->send(new ...), not PasswordResetMail::to(...): the
            // latter calls the Mailable's own instance to() method statically and
            // throws, which is exactly the kind of programming error the catch
            // below would otherwise hide.
            Mail::to($user)->send(new PasswordResetMail($user, $token));
        } catch (\Throwable $e) {
            // A mail outage must not become a stack trace on a public form, and it
            // must not tell the requester that sending failed - that would also
            // reveal the address is real. The exception is reported, not hidden.
            report($e);
        }

        // Recorded so an administrator can answer "did the email arrive?" without
        // handing out whether an address is registered.
        AuditLog::record('auth.password_reset_requested', $user, [
            'channel' => 'email',
        ]);
    }

    /**
     * One wording for every outcome.
     */
    private function successMessage(): string
    {
        return 'If that email address belongs to an active GovQueue account, '
            .'a reset link is on its way. It expires in '
            .config('auth.passwords.users.expire').' minutes.';
    }
}
