<?php

namespace Tests\Feature;

use App\Contracts\QueueAnnouncer;
use App\Contracts\QueueReader;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\QueueBroadcaster;
use App\Services\QueueReaderService;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The shape of the queue services, and the cycle that used to sit between them.
 *
 * QueueService announces every change it makes. QueueBroadcaster needs to read
 * the queue to build the event payload - a client's position and estimated wait.
 * So the two needed each other, and QueueService papered over it with a lazy
 * `app(QueueBroadcaster::class)` lookup inside a method, which is a circular
 * dependency expressed as a runtime trick.
 *
 * Two things are pinned here.
 *
 * First, that construction terminates. The obvious fix - bind the contract
 * instead of the class - does not work: the cycle is still there and the
 * container recurses until it exhausts memory. That was tried and it fails
 * loudly, so this test exists to keep anyone from "simplifying" the split back
 * into a cycle later.
 *
 * Second, that the read side genuinely cannot mutate. That is not a style
 * preference: the lobby display, a client's own page, and the announcer all read
 * the queue, and none of them should be able to move a ticket.
 */
class QueueAnnouncerContractTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'avg_service_minutes' => 10,
        ]);
        // Matches the window's configured average: with no completed history the
        // estimate falls back to the window's services, so a mismatched duration
        // would change the figure this test asserts for a reason of its own.
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'duration_minutes' => 10,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The cycle */
    /* ------------------------------------------------------------------ */

    public function test_the_queue_services_can_be_resolved_without_recursing(): void
    {
        // The regression this whole split exists to prevent. Before the split,
        // QueueService resolved QueueBroadcaster lazily inside a method; binding
        // the contract turned that into an eager loop that exhausted memory.
        $announcer = app(QueueAnnouncer::class);
        $reader = app(QueueReader::class);
        $queue = app(QueueService::class);

        $this->assertInstanceOf(QueueBroadcaster::class, $announcer);
        $this->assertInstanceOf(QueueReaderService::class, $reader);
        $this->assertInstanceOf(QueueService::class, $queue);
    }

    public function test_the_announcer_is_reachable_without_instantiating_the_mutator(): void
    {
        // Stated as a separate check because it is the property that actually
        // matters: resolving the announcer must not drag in the service that
        // changes tickets. If it did, every read-side caller would inherit the
        // write side again.
        $this->assertFalse(
            app(QueueAnnouncer::class) instanceof QueueService,
            'Resolving the announcer produced the mutator.'
        );
    }

    public function test_the_reader_does_not_depend_on_the_announcer_or_the_mutator(): void
    {
        // Read the declared constructor rather than the container, because the
        // container would happily resolve anything: this asserts the dependency
        // is not there at all.
        $constructor = (new \ReflectionClass(QueueReaderService::class))->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];

        // Takes no collaborators at all, which is the strongest form of the claim.
        // Asserted with a count so an empty parameter list is a pass rather than a
        // loop over nothing, which is how this assertion passed while asserting
        // nothing.
        $this->assertCount(
            0,
            $parameters,
            'QueueReaderService now takes '
            .implode(', ', array_map(fn ($p) => $p->getType()?->getName() ?? 'mixed', $parameters))
            .'. It is meant to know nothing about transitions or announcements, so a new '
            .'constructor dependency is either a good reason and needs justifying here, or it '
            .'is the cycle coming back.'
        );
    }

    public function test_queue_service_still_answers_the_queue_questions(): void
    {
        // The delegations kept on QueueService. Asserted so the convenience layer
        // cannot quietly stop forwarding: the staff console and the unit tests both
        // call these, and a stub that returned an empty collection would look like
        // a quiet queue rather than a bug.
        $this->fillQueue(3);

        $queue = app(QueueService::class);

        $this->assertCount(3, $queue->waitingFor($this->window));
        $this->assertNull($queue->currentlyServing($this->window));
        $this->assertNotEmpty($queue->estimateWaits($this->window, $queue->waitingFor($this->window)));
        $this->assertSame(10.0, $queue->averageServiceMinutes($this->window));
    }

    public function test_the_delegations_agree_with_the_reader(): void
    {
        // One implementation behind both, so the two can never drift.
        $this->fillQueue(2);

        $viaService = app(QueueService::class)->waitingFor($this->window)->pluck('id');
        $viaReader = app(QueueReader::class)->waitingFor($this->window)->pluck('id');

        $this->assertSame($viaReader->all(), $viaService->all());
    }

    /* ------------------------------------------------------------------ */
    /* The read side cannot write */
    /* ------------------------------------------------------------------ */

    public function test_the_reader_exposes_no_way_to_change_a_ticket(): void
    {
        // Read from the interface, which is what the announcer and the display
        // actually depend on. If a transition method ever appears here, the split
        // has been undone and this fails.
        $mutating = ['transition', 'checkIn', 'callNext', 'complete', 'markNoShow',
            'skip', 'verifyPriority', 'refusePriority', 'book', 'cancel', 'reschedule'];

        foreach ($mutating as $method) {
            $this->assertFalse(
                method_exists(QueueReader::class, $method),
                "QueueReader exposes {$method}(); it is meant to be read-only."
            );

            $this->assertFalse(
                method_exists(QueueReaderService::class, $method),
                "QueueReaderService exposes {$method}() despite the read-only contract."
            );
        }
    }

    public function test_reading_the_queue_changes_nothing(): void
    {
        $this->fillQueue(3);

        $before = Appointment::query()
            ->select('id', 'status', 'skip_count', 'queue_number')
            ->get()
            ->map(fn ($a) => $a->only(['id', 'status', 'skip_count', 'queue_number']))
            ->toArray();

        $reader = app(QueueReader::class);

        $reader->waitingFor($this->window);
        $reader->currentlyServing($this->window);
        $reader->expectedButNotArrived($this->window);
        $reader->averageServiceMinutes($this->window);

        $after = Appointment::query()
            ->select('id', 'status', 'skip_count', 'queue_number')
            ->get()
            ->map(fn ($a) => $a->only(['id', 'status', 'skip_count', 'queue_number']))
            ->toArray();

        $this->assertSame($before, $after, 'Reading the queue altered ticket state.');
    }

    /* ------------------------------------------------------------------ */
    /* locate(), which the event payload depends on */
    /* ------------------------------------------------------------------ */

    public function test_locate_reports_a_clients_position_and_wait(): void
    {
        $tickets = $this->fillQueue(3);

        [$position, $wait] = app(QueueReader::class)->locate($tickets[2]);

        $this->assertSame(3, $position);
        $this->assertSame(20, $wait, 'Three-deep at 10 minutes each should be a 20 minute wait.');
    }

    public function test_locate_reports_nothing_for_a_ticket_that_is_not_in_the_queue(): void
    {
        $tickets = $this->fillQueue(1);

        $tickets[0]->update(['status' => AppointmentStatus::Completed->value]);

        [$position, $wait] = app(QueueReader::class)->locate($tickets[0]->fresh());

        $this->assertNull($position);
        $this->assertNull($wait);
    }

    public function test_locate_agrees_with_what_the_client_page_would_show(): void
    {
        // The same number has to appear in the event payload and on the client's
        // own screen, or a client is told one thing and shown another.
        $tickets = $this->fillQueue(4);

        [$broadcastPosition] = app(QueueReader::class)->locate($tickets[3]);

        $queue = app(QueueReader::class)
            ->waitingFor($this->window)
            ->filter(fn (Appointment $a) => $a->status->isActive());

        $pagePosition = $queue->pluck('id')->search($tickets[3]->id) + 1;

        $this->assertSame($pagePosition, $broadcastPosition);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return Collection<int, Appointment>
     */
    private function fillQueue(int $count): Collection
    {
        $tickets = collect();

        for ($i = 0; $i < $count; $i++) {
            $start = today()->setTime(9, 0)->addMinutes($i * 30);

            $slot = TimeSlot::create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'slot_date' => today()->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
                'start_at' => $start->toDateTimeString(),
                'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
                'capacity' => 20,
                'booked_count' => 0,
                'is_open' => true,
            ]);

            $tickets->push(Appointment::create([
                'reference_number' => 'GQ-C-'.uniqid(),
                'user_id' => User::factory()->client()->create()->id,
                'service_id' => $this->service->id,
                'time_slot_id' => $slot->id,
                'service_window_id' => $this->window->id,
                'status' => AppointmentStatus::CheckedIn->value,
                'queue_number' => 'A-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'queue_date' => today()->toDateString(),
                // Arrival order matches queue order, so position assertions are
                // about ordering rather than about tie-breaking.
                'checked_in_at' => now()->subMinutes($count - $i),
            ]));
        }

        return $tickets;
    }
}
