<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QueueController extends Controller
{
    public function __construct(private QueueService $queue) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Staff are scoped to their assigned window by default, but may look at
        // any window when an administrator has asked them to cover.
        $windowCode = $request->query('w');

        if ($windowCode === null && $user->isStaff() && $user->serviceWindow) {
            $windowCode = $user->serviceWindow->code;
        }

        return view('staff.queue.index', [
            'staff' => $user,
            'initialWindow' => $windowCode,
            'today' => today()->toDateString(),
        ]);
    }

    /**
     * The window a member of staff is assigned to, if any.
     */
    public function myWindow(Request $request)
    {
        return $request->user()->serviceWindow;
    }
}
