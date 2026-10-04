<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function index(Request $request): View
    {
        $appointments = Appointment::query()
            ->with(['service', 'timeSlot', 'window'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at')
            ->get();

        return view('client.appointments.index', [
            'appointments' => $appointments,
            'upcoming' => $appointments->where('status', fn ($s) => $s->isActive()),
            'past' => $appointments->where('status', fn ($s) => $s->isClosed()),
        ]);
    }
}
