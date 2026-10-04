<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
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

        // PWD and pregnant claims cannot be inferred from a date of birth, so
        // the office needs a supporting reference on file.
        if (($claimsPwd || $claimsPregnant) && blank($data['priority_reference'] ?? null)) {
            throw ValidationException::withMessages([
                'priority_reference' => 'Please provide your PWD / medical / referral reference so staff can verify your priority claim.',
            ]);
        }

        if ($isSenior && blank($data['priority_reference'] ?? null)) {
            $data['priority_reference'] = 'Date of birth on file';
        }

        $user = DB::transaction(fn () => User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address' => $data['address'] ?? null,
            'barangay' => $data['barangay'],
            'birthdate' => $data['birthdate'],
            'password' => $data['password'],
            // Self-registration can only ever produce a client account. Staff
            // and admin accounts are created by an administrator (Sprint 8).
            'role' => UserRole::Client,
            'is_active' => true,
        ]));

        // Stash the claim so the next booking pre-selects it.
        session(['priority_claims' => [
            'senior_citizen' => $isSenior,
            'pwd' => $claimsPwd,
            'pregnant' => $claimsPregnant,
            'reference' => $data['priority_reference'] ?? null,
            'any' => $hasPriority,
        ]]);

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record('client.registered', $user, [
            'claims_priority' => $hasPriority,
        ]);

        return redirect()->route('client.appointments.index')
            ->with('success', $hasPriority
                ? 'Welcome to GovQueue. Your priority claim was recorded and will be verified by staff at the counter.'
                : 'Welcome to GovQueue. You can now book an appointment.');
    }
}
