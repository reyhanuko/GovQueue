<?php

namespace App\Http\Controllers;

use App\Services\UserHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single post-login landing point: sends each user to the console that matches
 * their role so there is exactly one place that decides "home" for signed-in users.
 *
 * The route it sends them to comes from UserHome, which is also what names
 * that screen. A redirect and a label that disagree are how a clerk ends up
 * being told to go to a dashboard they do not have.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, UserHome $home): RedirectResponse
    {
        return redirect()->route($home->routeFor($request->user()));
    }
}
