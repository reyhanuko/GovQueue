<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Failed attempts allowed per address, and per address+IP pair.
     *
     * Sign-in was previously unthrottled, which left password guessing against any
     * client account wide open on a public form.
     */
    private const MAX_ATTEMPTS_PER_EMAIL = 5;

    private const MAX_ATTEMPTS_PER_EMAIL_AND_IP = 10;

    /** How long the lockout lasts, in seconds. */
    private const DECAY_SECONDS = 60;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureNotRateLimited($request, $credentials['email']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Counted only on failure, so a legitimate client who signs in and out
            // repeatedly never locks themselves out.
            RateLimiter::hit($this->emailKey($request, $credentials['email']));
            RateLimiter::hit($this->emailAndIpKey($request, $credentials['email']));

            // Deliberately says nothing about whether the address exists.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $user = $request->user();

        // Deactivated accounts exist and must not be usable (see scope: accounts
        // are deactivated, never deleted, so history is preserved).
        if (! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Please contact the municipal office.',
            ]);
        }

        // A successful sign-in clears the counters, so a client who fat-fingers the
        // password a few times and then gets it right starts from a clean slate.
        RateLimiter::clear($this->emailKey($request, $credentials['email']));
        RateLimiter::clear($this->emailAndIpKey($request, $credentials['email']));

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::record('auth.login', $user);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Refuse a locked-out attempt, saying so on the email field.
     *
     * A bare 429 from the throttle middleware would show the client an error page
     * with no way to tell whether they mistyped or were blocked, which is the worst
     * possible outcome for someone locked out of a public service.
     */
    private function ensureNotRateLimited(Request $request, string $email): void
    {
        $message = 'Too many sign-in attempts. Please wait a minute and try again.';

        if (RateLimiter::tooManyAttempts($this->emailKey($request, $email), self::MAX_ATTEMPTS_PER_EMAIL)) {
            throw ValidationException::withMessages(['email' => $message])
                ->status(429);
        }

        if (RateLimiter::tooManyAttempts($this->emailAndIpKey($request, $email), self::MAX_ATTEMPTS_PER_EMAIL_AND_IP)) {
            throw ValidationException::withMessages(['email' => $message])
                ->status(429);
        }
    }

    /** Per account, regardless of where the attempt came from. */
    private function emailKey(Request $request, string $email): string
    {
        return 'login|'.Str::lower($email);
    }

    /**
     * Per account and address together.
     *
     * The per-account limit alone still permits a spray across many addresses from
     * one IP, which is what credential stuffing looks like.
     */
    private function emailAndIpKey(Request $request, string $email): string
    {
        return 'login-ip|'.$request->ip().'|'.Str::lower($email);
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuditLog::record('auth.logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'You have been signed out.');
    }
}
