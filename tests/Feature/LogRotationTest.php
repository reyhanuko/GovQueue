<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The log has to rotate.
 *
 * The single-file channel never does: one laravel.log, growing for as long as the
 * application is in use. On a development machine it reached 144MB in a week of
 * the browser suite running against it, and on a deployment it is the same file
 * until the disk complains.
 *
 * The audit trail this application actually keeps is in the database - the
 * audit_logs table, with its own pruning - so this file is diagnostics. It still
 * has to be a file somebody can open, and it still has to be a file that ends.
 */
class LogRotationTest extends TestCase
{
    public function test_a_log_line_lands_in_a_dated_file(): void
    {
        Log::info('a line for the rotation test');

        $file = storage_path('logs/laravel-'.now()->format('Y-m-d').'.log');

        $this->assertFileExists(
            $file,
            'The log is not rotating. One file that grows for as long as the '
                .'application is in use is a file that ends on the disk, not on the '
                .'log rotation.'
        );

        $this->assertStringContainsString(
            'a line for the rotation test',
            (string) file_get_contents($file),
        );
    }

    public function test_the_retention_window_is_set(): void
    {
        // A rotating channel with no window is a rotating channel that keeps
        // every file it has ever written, one per day, forever.
        $this->assertGreaterThan(
            0,
            (int) config('logging.channels.daily.days'),
            'The daily log channel keeps every file it has ever written.',
        );
    }

    public function test_the_default_channel_is_one_that_rotates(): void
    {
        $this->assertContains(
            config('logging.default'),
            ['daily', 'stack', 'stderr', 'syslog'],
            'The default log channel does not rotate, so the log file grows '
                .'without bound.',
        );
    }
}
