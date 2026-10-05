<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\SmsManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Notification delivery history.
 *
 * Exists because the project explicitly does not promise message delivery: staff
 * need to see which messages were queued, sent, or failed, and why.
 */
class NotificationLogController extends Controller
{
    public function __construct(private SmsManager $sms) {}

    public function index(Request $request): View
    {
        $logs = NotificationLog::query()
            ->with(['appointment', 'user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')->toString()))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $counts = NotificationLog::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.notifications.index', [
            'logs' => $logs,
            'counts' => [
                'queued' => (int) ($counts[NotificationStatus::Queued->value] ?? 0),
                'sent' => (int) ($counts[NotificationStatus::Sent->value] ?? 0),
                'failed' => (int) ($counts[NotificationStatus::Failed->value] ?? 0),
            ],
            'smsGateway' => $this->sms->gateway()->name(),
            'smsWarning' => $this->sms->configurationWarning(),
            'filters' => $request->only(['status', 'channel', 'type']),
        ]);
    }
}
