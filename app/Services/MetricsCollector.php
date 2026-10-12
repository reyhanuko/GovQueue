<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;

/**
 * Operational counters, in Prometheus text exposition format.
 *
 * Hand-rolled rather than pulled from a client library: the format is a few lines
 * of string building, and adding a dependency for it would mean a lockfile change
 * and a release process for something this small.
 *
 * What is reported is deliberately about the *system*, not the residents. Queue
 * depth, how many tickets were served, how many messages failed to send - no names,
 * no phone numbers, no service names that could be traced back to an individual
 * client. A metrics endpoint is polled on a timer by something outside the
 * application, and anything it exposes is effectively long-lived and widely read.
 *
 * Every metric is a gauge of current state rather than a counter of events. A
 * counter needs a process to increment it and a place for the total to live; the
 * application's answers to "how long is the queue right now" are all questions
 * about the database, and the database is the only place that knows.
 */
class MetricsCollector
{
    /**
     * Render the exposition format.
     */
    public function render(): string
    {
        $lines = [];

        $this->queueMetrics($lines);
        $this->serviceMetrics($lines);
        $this->notificationMetrics($lines);
        $this->slotMetrics($lines);

        return implode("\n", $lines)."\n";
    }

    /**
     * Tickets on the books today, by status.
     */
    private function queueMetrics(array &$lines): void
    {
        $counts = Appointment::query()
            ->whereDate('queue_date', today()->toDateString())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $lines[] = '# HELP govqueue_appointments_today Appointments belonging to today, by status.';
        $lines[] = '# TYPE govqueue_appointments_today gauge';

        foreach ($counts as $status => $total) {
            $lines[] = sprintf(
                'govqueue_appointments_today{status="%s"} %d',
                // ->value, not a string cast. Appointment casts status to a backed
                // enum, and PHP forbids casting an enum to a string.
                $this->escape($status instanceof AppointmentStatus ? $status->value : (string) $status),
                (int) $total
            );
        }

        // A window with nothing booked still has to appear, or the metric goes
        // missing and a dashboard treats the gap as "no data" rather than "zero".
        if ($counts->isEmpty()) {
            $lines[] = 'govqueue_appointments_today{status="none"} 0';
        }
    }

    /**
     * How many windows are active, and how many are being worked right now.
     */
    private function serviceMetrics(array &$lines): void
    {
        $active = ServiceWindow::where('is_active', true)->count();

        $serving = Appointment::where('status', 'serving')
            ->whereDate('queue_date', today()->toDateString())
            ->count();

        $waiting = Appointment::whereIn('status', ['checked_in', 'skipped'])
            ->whereDate('queue_date', today()->toDateString())
            ->count();

        $lines[] = '# HELP govqueue_active_windows Service windows currently active.';
        $lines[] = '# TYPE govqueue_active_windows gauge';
        $lines[] = 'govqueue_active_windows '.$active;

        $lines[] = '# HELP govqueue_clients_waiting Clients physically at a counter right now.';
        $lines[] = '# TYPE govqueue_clients_waiting gauge';
        $lines[] = 'govqueue_clients_waiting '.$waiting;

        $lines[] = '# HELP govqueue_clients_being_served Clients currently at a window.';
        $lines[] = '# TYPE govqueue_clients_being_served gauge';
        $lines[] = 'govqueue_clients_being_served '.$serving;
    }

    /**
     * Notification outcomes, which is where a silent failure would show up.
     */
    private function notificationMetrics(array &$lines): void
    {
        $counts = NotificationLog::query()
            // where(), not whereDate(). whereDate() wraps the column in DATE(),
            // so '>= yesterday' would match everything from midnight yesterday
            // onwards - up to 47 hours at 23:00, all reported under a metric
            // labelled "the last 24h".
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('channel, status, COUNT(*) as total')
            ->groupBy('channel', 'status')
            ->get();

        $lines[] = '# HELP govqueue_notifications Notifications attempted in the last 24h, by channel and status.';
        $lines[] = '# TYPE govqueue_notifications gauge';

        foreach ($counts as $row) {
            $lines[] = sprintf(
                'govqueue_notifications{channel="%s",status="%s"} %d',
                // ->value, not a string cast: the model casts both columns to
                // backed enums, and PHP does not permit casting an enum object to
                // a string. A plain cast fatals here, which surfaces as an HTML
                // error page where a metrics body should be.
                $this->escape($row->channel->value),
                $this->escape($row->status->value),
                (int) $row->total
            );
        }
    }

    /**
     * Bookable capacity still on offer, and how much of it is already taken.
     */
    private function slotMetrics(array &$lines): void
    {
        $row = TimeSlot::query()
            ->whereDate('slot_date', '>=', today()->toDateString())
            ->selectRaw('SUM(capacity) as capacity')
            ->selectRaw('SUM(booked_count) as booked')
            ->first();

        $lines[] = '# HELP govqueue_open_slot_capacity Total places offered from today onwards.';
        $lines[] = '# TYPE govqueue_open_slot_capacity gauge';
        $lines[] = 'govqueue_open_slot_capacity '.(int) ($row->capacity ?? 0);

        $lines[] = '# HELP govqueue_booked_slot_places Places currently held, from today onwards.';
        $lines[] = '# TYPE govqueue_booked_slot_places gauge';
        $lines[] = 'govqueue_booked_slot_places '.(int) ($row->booked ?? 0);
    }

    /**
     * Escape a label value.
     *
     * Only reached by enum values and window codes, so this is belt-and-braces
     * rather than a live risk - but a stray quote or newline in a label value is
     * how a metric file silently breaks a scrape for everyone downstream.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\"', '\n'],
            $value
        );
    }
}
