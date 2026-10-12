<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\ReportingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
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
            'dateBounds' => $this->reports->dateBounds(),
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
     * The same report as a printable PDF.
     *
     * Offered alongside the CSV, not instead of it. A PDF is what gets printed
     * and put in a file, which is the point for an office reporting to a
     * provincial office; the CSV is what somebody actually works in when they
     * want to sort or total a column. Both take the same period query string,
     * so what is on screen is what is downloaded.
     *
     * Rendered from the same ReportingService calls as the screen and the CSV,
     * rather than scraped from the HTML page: a PDF built by scraping its own
     * view would break every time the screen was restyled, and quietly.
     */
    public function exportPdf(Request $request): Response
    {
        [$from, $to, $days] = $this->reports->periodFrom(
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
        );

        $filename = sprintf('govqueue-report-%s-to-%s.pdf', $from->toDateString(), $to->toDateString());

        // No remote assets. DomPDF cannot fetch stylesheets or images over the
        // network reliably, so any external asset either silently fails or adds
        // seconds per page; the template is self-contained by design.
        $pdf = Pdf::loadView('admin.reports.pdf', [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'summary' => $this->reports->summary($from, $to),
            'services' => $this->reports->servicePerformance($from, $to),
            'windows' => $this->reports->windowPerformance($from, $to),
            'peakHours' => $this->reports->peakHours($from, $to),
            'priority' => $this->reports->priorityHandling($from, $to),
            'presets' => $this->reports->presets(),
            'office' => config('govqueue.office.lgu'),
            'generatedAt' => now(),
        ])->setPaper('a4')->setOption('isRemoteEnabled', false);

        return $pdf->download($filename);
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
