<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ClientProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * A client editing their own profile. Priority entitlement is derived from the
 * birthdate here, so the client cannot grant themselves senior status.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();
        $profileService = app(ClientProfileService::class);

        return view('client.profile.edit', [
            'user' => $user,
            'eligibility' => $profileService->explainEligibility($user),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profileService = app(ClientProfileService::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:20', 'regex:/^09\d{9}$/'],
            'barangay' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['required', 'date', 'before:today', 'after:1900-01-01'],
        ], [
            'phone.regex' => 'Enter an 11-digit Philippine mobile number starting with 09.',
        ]);

        // Updating the birthdate can add or remove RA 9994 priority, so let the
        // profile service decide rather than trusting submitted input.
        $user->fill($data)->save();
        $profileService->syncSeniorCitizenClaim($user);

        return redirect()->route('client.profile.edit')
            ->with('success', 'Profile updated.');
    }

    /**
     * Notification preferences, kept on their own screen.
     *
     * Separate from the profile form so that the checkbox pair cannot be lost in a
     * profile submission - an unchecked box is absent from the payload, so a
     * client who unticked both and then changed their address would silently get
     * their preferences back.
     */
    public function updateNotifications(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Read from the request rather than the validated array: an unticked box
        // simply is not in the payload, so 'nullable' alone would keep the old
        // value instead of turning it off.
        $wantsEmail = $request->boolean('notify_email');
        $wantsSms = $request->boolean('notify_sms');

        $user->update([
            'notify_email' => $wantsEmail,
            'notify_sms' => $wantsSms,
        ]);

        $message = match (true) {
            ! $wantsEmail && ! $wantsSms => 'Notifications turned off. You will only see appointment '
                .'changes when you sign in to GovQueue.',
            ! $wantsSms => 'Email notifications on. SMS reminders turned off.',
            ! $wantsEmail => 'SMS notifications on. Email turned off.',
            default => 'Email and SMS notifications are on.',
        };

        return redirect()->route('client.profile.edit')->with('success', $message);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return redirect()->route('client.profile.edit')
            ->with('success', 'Password changed.');
    }
}
