<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics and reporting.
 *
 * Everything is scoped to a period chosen by the administrator, and every figure
 * that could be empty says so rather than showing a zero. See ReportingService
 * for the rules that shaped this.
 */
class ReportController extends Controller
{
    public function __construct(private ReportingService $reports) {}

    public function index(Request $request): View
    {
        [$from, $to, $days] = $this->reports->periodFrom(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
        );

        return view('admin.reports.index', [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'summary' => $this->reports->summary($from, $to),
            'services' => $this->reports->servicePerformance($from, $to),
            'windows' => $this->reports->windowPerformance($from, $to),
            'peakHours' => $this->reports->peakHours($from, $to),
            'trend' => $this->reports->dailyTrend($from, $to),
            'priority' => $this->reports->priorityHandling($from, $to),
            'cancellationReasons' => $this->reports->cancellationReasons($from, $to),
            'presets' => $this->reports->presets(),
            'filters' => $request->only(['period', 'from', 'to']),
        ]);
    }

    /**
     * Spreadsheet-friendly export of the per-service figures.
     *
     * Built as a string and returned as a normal download. Writing to a
     * php://output handle first and then handing that same handle to
     * streamDownload() would send an already-closed stream, producing an empty
     * file with a 200.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->reports->periodFrom(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
        );

        $rows = $this->reports->servicePerformance($from, $to)->map(fn (array $row) => [
            'Service' => $row['service']->name,
            'Code' => $row['service']->code,
            'Window' => $row['service']->window?->name ?? '',
            'Booked' => $row['booked'],
            'Completed' => $row['completed'],
            'Cancelled' => $row['cancelled'],
            'No show' => $row['no_show'],
            'Skipped' => $row['skipped'],
            'Places offered' => $row['capacity'],
            'Utilisation %' => $row['utilisation'] ?? 'no capacity offered',
            'Slots filled' => $row['filled_slots'],
            'Average wait (min)' => $row['avg_wait'] ?? 'no data',
            'Wait samples' => $row['wait_samples'],
        ])->all();

        $csv = $this->toCsv($rows);

        $filename = sprintf('govqueue-report-%s-to-%s.csv', $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Render rows as CSV, with a byte-order mark.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // BOM so Excel opens the peso sign and Filipino text correctly instead of
        // showing mojibake.
        fwrite($handle, "\xEF\xBB\xBF");

        if ($rows === []) {
            fputcsv($handle, ['No services configured for this period']);
        } else {
            fputcsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
