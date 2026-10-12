<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The audit trail.
 *
 * Every administrator action records who did it, to what, and with what result.
 * This screen exists so that record is actually readable - an audit log nobody
 * can query is not an audit trail.
 *
 * Payloads are shown as stored JSON. They are written by GovQueue itself and
 * contain configuration values, never client personal data, so this screen stays
 * administrator-only like every other route in the group.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AuditLog::query()
            ->with('user')
            ->when($request->filled('action'), function ($q) use ($request) {
                // Prefix match, so 'service.' shows every service action.
                $action = $request->string('action')->trim();

                $q->where('action', 'like', str_contains($action, '%') ? $action : $action.'%');
            })
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
            ->when($request->filled('subject'), function ($q) use ($request) {
                $q->where('subject_type', $request->string('subject')->toString());
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'user', 'subject', 'from', 'to']),
            'actions' => AuditLog::query()
                ->selectRaw('action, COUNT(*) as total')
                ->groupBy('action')
                ->orderBy('action')
                ->pluck('total', 'action'),
            'subjects' => AuditLog::query()
                ->whereNotNull('subject_type')
                ->distinct()
                ->orderBy('subject_type')
                ->pluck('subject_type'),
            // Bounds the date inputs. A mistyped year used to return a silently
            // empty trail, which reads as "nothing has ever been changed here".
            //
            // min() returns the raw column value rather than a cast model, so it
            // is parsed here - calling format() on the string would fatal on the
            // first request of a day.
            'dateBounds' => [
                Carbon::parse(AuditLog::query()->min('created_at') ?? 'now')->toDateString(),
                today()->toDateString(),
            ],
        ]);
    }
}
