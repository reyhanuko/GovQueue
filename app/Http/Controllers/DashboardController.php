<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single post-login landing point: sends each user to the console that matches
 * their role so there is exactly one place that decides "home" for signed-in users.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return match (true) {
            $user->isAdmin() => redirect()->route('admin.dashboard'),
            $user->isStaff() => redirect()->route('staff.dashboard'),
            default => redirect()->route('client.appointments.index'),
        };
    }
}
