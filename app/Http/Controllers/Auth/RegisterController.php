<?php

namespace App\Http\Controllers\Auth;

use App\Enums\PriorityType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ClientProfileService;
use App\Services\OpsNotificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

            // Priority is a *claim* here. It confers no precedence until staff
            // verify it at the counter - see the project's stated limitation.
            'claims_senior_citizen' => ['nullable', 'boolean'],
            'claims_pwd' => ['nullable', 'boolean'],
            'claims_pregnant' => ['nullable', 'boolean'],
            'priority_reference' => ['nullable', 'string', 'max:120'],
            'priority_reference_pwd' => ['nullable', 'string', 'max:120'],
            'priority_reference_pregnant' => ['nullable', 'string', 'max:120'],
            'privacy_consent' => ['accepted'],
        ], [
            'phone.regex' => 'Enter an 11-digit Philippine mobile number starting with 09.',
            'birthdate.before' => 'Enter a valid date of birth.',
            'privacy_consent.accepted' => 'You must agree to the data privacy notice to register.',
        ]);

        // A birthdate that already proves the RA 9994 threshold does not need a
        // ticked box, so auto-set the claim for the client.
        $isSenior = filter_var($data['claims_senior_citizen'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || Carbon::parse($data['birthdate'])->age >= config('govqueue.senior_citizen_age');

        $claimsPwd = filter_var($data['claims_pwd'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $claimsPregnant = filter_var($data['claims_pregnant'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $hasPriority = $isSenior || $claimsPwd || $claimsPregnant;

        /*
         * One reference per claim, kept together.
         *
         * A client may claim PWD and pregnancy at the same time, and each has
         * its own document, so each has its own reference. A single field for
         * both made the client write two numbers into one box, and the staff
         * had no way to tell which was which.
         */
        $references = [];

        if ($claimsPwd) {
            $references[PriorityType::Pwd->value] = $data['priority_reference_pwd'] ?? null;
        }

        if ($claimsPregnant) {
            $references[PriorityType::Pregnant->value] = $data['priority_reference_pregnant'] ?? null;
        }

        foreach ($references as $category => $reference) {
            if (blank($reference)) {
                throw ValidationException::withMessages([
                    'priority_reference_'.$category => 'Please provide the reference for this claim so staff can verify your priority claim.',
                ]);
            }
        }

        $reference = PriorityType::joinReferences($references);

        if ($isSenior && blank($reference)) {
            $reference = 'Date of birth on file';
        }

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

        // Stash the claim so the next booking pre-selects it.
        session(['priority_claims' => [
            'senior_citizen' => $isSenior,
            'pwd' => $claimsPwd,
            'pregnant' => $claimsPregnant,
            'reference' => $reference,
            'references' => $references,
            'any' => $hasPriority,
        ]]);

        // Persist the derived RA 9994 entitlement so the profile and the
        // booking form do not each recompute it.
        app(ClientProfileService::class)->syncSeniorCitizenClaim($user);

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record('client.registered', $user, [
            'claims_priority' => $hasPriority,
        ]);

        /*
         * An administrator is told a new account exists, because priority is
         * verified at the counter and somebody has to be in a position to
         * arrange that. Staff are not told: they meet the client at the window
         * and verify there, so a message would only be noise.
         */
        app(OpsNotificationService::class)->clientRegistered($user, $hasPriority);

        return redirect()->route('client.appointments.index')
            ->with('success', $hasPriority
                ? 'Welcome to GovQueue. Your priority claim was recorded and will be verified by staff at the counter.'
                : 'Welcome to GovQueue. You can now book an appointment.');
    }

    private function registrationKey(Request $request): string
    {
        return 'register|'.$request->ip();
    }
}
