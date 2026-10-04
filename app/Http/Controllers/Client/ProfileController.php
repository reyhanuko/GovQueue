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
