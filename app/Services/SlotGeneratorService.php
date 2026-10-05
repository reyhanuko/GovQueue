<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Service;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Expands recurring slot_templates into concrete time_slots rows.
 *
 * Concrete rows (rather than computing availability on the fly) are what make
 * the Sprint 3 capacity lock meaningful: booking and counting both hit the same
 * row inside a transaction.
 */
class SlotGeneratorService
{
    /**
     * Generate slots for every active service between two dates.
     *
     * @return array{created:int, skipped:int}
     */
    public function generate(Carbon|string $from, Carbon|string $to, bool $includeExisting = true): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        $created = 0;
        $skipped = 0;

        $templates = SlotTemplate::with('service')->active()->get();

        $closedDates = Holiday::query()->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (in_array($date->toDateString(), $closedDates, true)) {
                continue;
            }

            foreach ($templates as $template) {
                if (! $template->appliesOn(Carbon::instance($date->copy()->startOfDay()))) {
                    continue;
                }

                foreach ($this->expandTemplate($template, $date) as $slot) {
                    $existing = TimeSlot::where([
                        'service_id' => $template->service_id,
                        'slot_date' => $date->toDateString(),
                        'start_time' => $slot['start_time'],
                    ])->first();

                    if ($existing) {
                        if ($includeExisting) {
                            /*
                             * Capacity follows the template downwards, but never
                             * below the clients already holding the slot.
                             *
                             * This used to be max(existing, template), which meant
                             * an administrator lowering a template from 4 to 2
                             * silently had no effect on any slot that had ever
                             * been generated - the old number was always the
                             * larger one. Comparing against booked_count instead
                             * is what the comment always claimed to do.
                             */
                            $existing->update([
                                'end_time' => $slot['end_time'],
                                'capacity' => max($slot['capacity'], (int) $existing->booked_count),
                                'start_at' => $slot['start_at'],
                                'end_at' => $slot['end_at'],
                                'service_window_id' => $template->service->service_window_id,
                            ]);
                        }

                        $skipped++;

                        continue;
                    }

                    TimeSlot::create([
                        'service_id' => $template->service_id,
                        'service_window_id' => $template->service->service_window_id,
                        'slot_date' => $date->toDateString(),
                        ...$slot,
                        'booked_count' => 0,
                        'is_open' => true,
                    ]);

                    $created++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Chop a template's time range into fixed-length slots.
     *
     * @return Collection<int, array<string, string|int>>
     */
    private function expandTemplate(SlotTemplate $template, Carbon $date): Collection
    {
        $cursor = $date->copy()->setTimeFromTimeString($template->start_time);
        $end = $date->copy()->setTimeFromTimeString($template->end_time);

        $slots = collect();

        while ($cursor->lt($end)) {
            $slotEnd = $cursor->copy()->addMinutes($template->slot_minutes);

            // Do not emit a slot that would run past the template's end time.
            if ($slotEnd->gt($end)) {
                break;
            }

            $slots->push([
                'start_time' => $cursor->format('H:i:s'),
                'end_time' => $slotEnd->format('H:i:s'),
                'start_at' => $cursor->format('Y-m-d H:i:s'),
                'end_at' => $slotEnd->format('Y-m-d H:i:s'),
                'capacity' => $template->capacity,
            ]);

            $cursor = $slotEnd;
        }

        return $slots;
    }

    /** Convenience helper used by tests and the demo seeder. */
    public function ensureSlotsForService(Service $service, Carbon|string $date): Collection
    {
        $this->generate($date, $date);

        return TimeSlot::where('service_id', $service->id)
            ->forDate($date)
            ->orderBy('start_time')
            ->get();
    }
}
