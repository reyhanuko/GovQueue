<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OpsNotificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisterController extends Controller
{
    /**
     * Accounts one address may create per hour.
     *
     * Self-registration was previously unthrottled. The unique email rule stops
     * duplicates, but not one person scripting hundreds of accounts from a single
     * address, which pollutes the client list staff have to work from.
     */
    private const MAX_REGISTRATIONS_PER_HOUR = 5;

    private const DECAY_SECONDS = 3600;

    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        if (RateLimiter::tooManyAttempts($this->registrationKey($request), self::MAX_REGISTRATIONS_PER_HOUR)) {
            // Reported on the form rather than as a bare 429, so a resident who
            // genuinely needs several accounts is not left with a blank error page.
            throw ValidationException::withMessages([
                'email' => 'Too many accounts have been created from this connection. '
                    .'Please try again later, or ask staff to add you.',
            ])->status(429);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^09\d{9}$/'],
            'barangay' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'privacy_consent' => ['accepted'],
        ], [
            'phone.regex' => 'Enter an 11-digit Philippine mobile number starting with 09.',
            'birthdate.before' => 'Enter a valid date of birth.',
            'privacy_consent.accepted' => 'You must agree to the data privacy notice to register.',
        ]);

        /*
         * No priority is accepted here, and none is stored.
         *
         * The account records who somebody is. It does not record what they
         * may claim on any given transaction:
         *
         *  - Senior citizen (RA 9994) is a fact about the date of birth, so it
         *    is derived from it (User::meetsSeniorCitizenAge) and never ticked.
         *  - PWD (RA 10754) and pregnancy are claims made against a document
         *    when an appointment is booked, and staff verify them at the
         *    counter. They belong to the transaction, not to the account, and
         *    storing them here produced claims that went stale the moment the
         *    client was not claiming them.
         *
         * The date of birth is therefore the only priority-related input on
         * this form, and it is kept for the RA 9994 threshold and for the
         * office's records.
         */
        $user = DB::transaction(fn () => User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address' => $data['address'] ?? null,
            'barangay' => $data['barangay'],
            'birthdate' => $data['birthdate'],
            'password' => $data['password'],
            // Self-registration can only ever produce a client account. Staff and
            // admin accounts are created by an administrator from /admin/staff.
            'role' => UserRole::Client,
            'is_active' => true,
        ]));

        RateLimiter::hit($this->registrationKey($request), self::DECAY_SECONDS);

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record('client.registered', $user, [
            'senior_citizen' => $user->meetsSeniorCitizenAge(),
        ]);

        /*
         * An administrator is told a new account exists. No priority claim
         * goes with it: a client who will claim PWD or pregnancy does that on
         * the booking, where there is a document for staff to check, so there
         * is nothing here for an administrator to arrange.
         */
        app(OpsNotificationService::class)->clientRegistered($user);

        return redirect()->route('client.appointments.index')
            ->with('success', 'Welcome to GovQueue. You can now book an appointment.');
    }

    private function registrationKey(Request $request): string
    {
        return 'register|'.$request->ip();
    }
}
