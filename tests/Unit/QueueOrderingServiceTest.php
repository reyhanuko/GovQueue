<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Services\QueueOrderingService;
use App\Services\QueueReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueOrderingServiceTest extends TestCase
{
    use RefreshDatabase;

    private QueueOrderingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QueueOrderingService;
    }

    /**
     * Build an on-site ticket without dragging in slot/window fixtures, since
     * these tests exercise ordering rather than the booking path.
     */
    private function ticket(
        AppointmentStatus $status,
        ?string $checkedInAt = null,
        array $priority = [],
        string $reference = 'GQ-REF-001',
    ): Appointment {
        return new Appointment([
            'status' => $status,
            'checked_in_at' => $checkedInAt,
            'priority_verified_at' => ($priority['verified'] ?? false) ? now() : null,
            'claims_senior_citizen' => $priority['senior'] ?? false,
            'claims_pwd' => $priority['pwd'] ?? false,
            'claims_pregnant' => $priority['pregnant'] ?? false,
            'reference_number' => $reference,
        ]);
    }

    public function test_a_verified_pwd_client_outranks_a_regular_client_who_arrived_earlier(): void
    {
        // RA 10754: precedence must beat arrival order.
        $regular = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:00:00', reference: 'GQ-REG');
        $pwd = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:30:00',
            ['pwd' => true, 'verified' => true], 'GQ-PWD');

        $sorted = $this->service->sortCollection(collect([$regular, $pwd]));

        $this->assertSame('GQ-PWD', $sorted->first()->reference_number);
    }

    public function test_a_verified_senior_citizen_outranks_a_regular_client(): void
    {
        // RA 9994.
        $regular = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:00:00', reference: 'GQ-REG');
        $senior = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:20:00',
            ['senior' => true, 'verified' => true], 'GQ-SEN');

        $sorted = $this->service->sortCollection(collect([$regular, $senior]));

        $this->assertSame('GQ-SEN', $sorted->first()->reference_number);
    }

    public function test_an_unverified_priority_claim_gets_no_precedence(): void
    {
        // The stated limitation: the system does not verify eligibility itself,
        // so an unchecked claim must not jump the queue.
        $regular = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:00:00', reference: 'GQ-REG');
        $unverifiedPwd = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:30:00',
            ['pwd' => true, 'verified' => false], 'GQ-PWD-UNVERIFIED');

        $sorted = $this->service->sortCollection(collect([$regular, $unverifiedPwd]));

        $this->assertSame('GQ-REG', $sorted->first()->reference_number,
            'An unverified claim must be treated as an ordinary client.');
    }

    public function test_within_the_same_group_the_earlier_arrival_goes_first(): void
    {
        $late = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 09:00:00', reference: 'GQ-LATE');
        $early = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:00:00', reference: 'GQ-EARLY');

        $sorted = $this->service->sortCollection(collect([$late, $early]));

        $this->assertSame(['GQ-EARLY', 'GQ-LATE'], $sorted->pluck('reference_number')->all());
    }

    public function test_the_client_at_the_window_is_ordered_first(): void
    {
        $serving = $this->ticket(AppointmentStatus::Serving, '2026-10-04 08:30:00', reference: 'GQ-SERVING');
        $pwdWaiting = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:05:00',
            ['pwd' => true, 'verified' => true], 'GQ-PWD');

        $sorted = $this->service->sortCollection(collect([$pwdWaiting, $serving]));

        $this->assertSame('GQ-SERVING', $sorted->first()->reference_number);
    }

    public function test_a_skipped_client_goes_behind_those_still_waiting(): void
    {
        // "Not ready" must not hand the window straight back to the same person.
        $skipped = $this->ticket(AppointmentStatus::Skipped, '2026-10-04 08:00:00', reference: 'GQ-SKIPPED');
        $stillWaiting = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:20:00', reference: 'GQ-WAITING');

        $sorted = $this->service->sortCollection(collect([$skipped, $stillWaiting]));

        $this->assertSame('GQ-WAITING', $sorted->first()->reference_number,
            'A skipped client goes behind the people still waiting, whoever arrived first.');
    }

    public function test_stepping_back_defers_a_priority_client(): void
    {
        // Documented behaviour: stage is ranked before priority. A client who
        // says "not ready" has deferred their own turn, so they go behind the
        // people actually waiting - verified priority or not. RA 9994 still
        // governs among clients who are waiting (see the other ordering tests).
        $skippedSenior = $this->ticket(AppointmentStatus::Skipped, '2026-10-04 08:00:00',
            ['senior' => true, 'verified' => true], 'GQ-SKIPPED-SENIOR');
        $regular = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:30:00', reference: 'GQ-REGULAR');

        $sorted = $this->service->sortCollection(collect([$skippedSenior, $regular]));

        $this->assertSame('GQ-REGULAR', $sorted->first()->reference_number);
    }

    public function test_a_verified_priority_client_leads_other_waiting_clients(): void
    {
        // The statutory case that matters: both parties are waiting.
        $senior = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:00:00',
            ['senior' => true, 'verified' => true], 'GQ-SENIOR');
        $regular = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:30:00', reference: 'GQ-REGULAR');

        $sorted = $this->service->sortCollection(collect([$regular, $senior]));

        $this->assertSame('GQ-SENIOR', $sorted->first()->reference_number);
    }

    public function test_clients_who_have_not_arrived_are_ordered_last(): void
    {
        $confirmed = $this->ticket(AppointmentStatus::Confirmed, null, reference: 'GQ-CONF');
        $checkedIn = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 10:00:00', reference: 'GQ-CHECKED');

        $sorted = $this->service->sortCollection(collect([$confirmed, $checkedIn]));

        $this->assertSame('GQ-CHECKED', $sorted->first()->reference_number);
    }

    public function test_ordering_is_stable_for_identical_tickets(): void
    {
        $a = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:00:00', reference: 'GQ-AAA');
        $b = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:00:00', reference: 'GQ-BBB');

        // Reference number is the final tie-break, so repeated sorts agree.
        $first = $this->service->sortCollection(collect([$a, $b]))->pluck('reference_number')->all();
        $second = $this->service->sortCollection(collect([$b, $a]))->pluck('reference_number')->all();

        $this->assertSame($first, $second);
        $this->assertSame(['GQ-AAA', 'GQ-BBB'], $first);
    }

    /* ------------------------------------------------------------------ */
    /* Skipped verified-priority clients are re-inserted */
    /* ------------------------------------------------------------------ */

    public function test_a_skipped_priority_client_is_reinserted_near_the_front(): void
    {
        // Arrival order: SC first, then four regulars. Skipping drops the SC
        // ticket to the very back, which is wrong for a statutory claim.
        $senior = $this->ticket(
            AppointmentStatus::Skipped,
            '2026-10-04 08:00:00',
            ['senior' => true, 'verified' => true],
            'GQ-SC',
        );

        $regulars = collect(range(1, 4))->map(fn ($i) => $this->ticket(
            AppointmentStatus::CheckedIn,
            sprintf('2026-10-04 08:%02d:00', $i * 5),
            reference: 'GQ-R'.$i,
        ));

        $sorted = $this->service->sortCollection($regulars->push($senior));

        // Behind two of the people who arrived after them, ahead of the rest.
        $this->assertSame('GQ-R1', $sorted[0]->reference_number);
        $this->assertSame('GQ-R2', $sorted[1]->reference_number);
        $this->assertSame('GQ-SC', $sorted[2]->reference_number);
    }

    public function test_a_skipped_regular_client_still_goes_to_the_back(): void
    {
        $regular = $this->ticket(
            AppointmentStatus::Skipped,
            '2026-10-04 08:00:00',
            reference: 'GQ-SKIPPED',
        );

        $regulars = collect(range(1, 4))->map(fn ($i) => $this->ticket(
            AppointmentStatus::CheckedIn,
            sprintf('2026-10-04 08:%02d:00', $i * 5),
            reference: 'GQ-R'.$i,
        ));

        $sorted = $this->service->sortCollection($regulars->push($regular));

        $this->assertSame('GQ-SKIPPED', $sorted->last()->reference_number);
    }

    public function test_an_unverified_priority_claim_gets_no_mercy(): void
    {
        // Claimed but not verified: treated as an ordinary client on purpose,
        // so skipping one sends them to the back like anybody else.
        $claimed = $this->ticket(
            AppointmentStatus::Skipped,
            '2026-10-04 08:00:00',
            ['pwd' => true],
            'GQ-CLAIMED',
        );

        $regulars = collect(range(1, 3))->map(fn ($i) => $this->ticket(
            AppointmentStatus::CheckedIn,
            sprintf('2026-10-04 08:%02d:00', $i * 5),
            reference: 'GQ-R'.$i,
        ));

        $sorted = $this->service->sortCollection($regulars->push($claimed));

        $this->assertSame('GQ-CLAIMED', $sorted->last()->reference_number);
    }

    public function test_several_skipped_priority_clients_are_all_reinserted(): void
    {
        /*
         * Two statutory clients, both skipped, both at the back.
         *
         * The existing tests all use a single skipped priority ticket, which is
         * the one case the splice arithmetic happens to get right. With two, the
         * descending loop moved the ticket at the highest index first; that
         * splice shifted everything between the target and that index up by one,
         * so the cursor's next position held a different ticket than intended and
         * the second priority client was never examined at all.
         *
         * It ended up behind an ordinary client who arrived later - which is
         * exactly what the whole rule exists to prevent - and the two priority
         * tickets came out in the reverse of their canonical order, contradicting
         * the method's own docblock.
         */
        config(['govqueue.priority_skip_reinsert_after' => 2]);

        // Both seniors, deliberately: they share a priority weight, so their
        // canonical order is decided by arrival alone. Using two different
        // categories here would make the test also depend on the configured
        // weight order, and a failure would not say which of the two rules broke.
        $first = $this->ticket(
            AppointmentStatus::Skipped,
            '2026-10-04 08:00:00',
            ['senior' => true, 'verified' => true],
            'GQ-SC-1',
        );

        $second = $this->ticket(
            AppointmentStatus::Skipped,
            '2026-10-04 08:10:00',
            ['senior' => true, 'verified' => true],
            'GQ-SC-2',
        );

        $regulars = collect(range(1, 3))->map(fn ($i) => $this->ticket(
            AppointmentStatus::CheckedIn,
            sprintf('2026-10-04 08:%02d:00', $i * 5),
            reference: 'GQ-R'.$i,
        ));

        $sorted = $this->service->sortCollection($regulars->push($first)->push($second));

        $references = $sorted->pluck('reference_number')->all();

        /*
         * Both statutory tickets, and the one ordinary client who arrived after
         * both of them, must appear in that order.
         *
         * Before the fix this produced R1,R2,R3,PWD-2,SC-1 - the senior citizen
         * ended up behind an ordinary client who arrived fifteen minutes after
         * them, and the two statutory tickets came out reversed.
         *
         * Asserted as a whole-order comparison rather than as fixed indices: each
         * ticket is reinserted at min(index, keep), so a pair both aiming at
         * position 2 legitimately occupies positions 2 and 3. What matters is
         * that nothing ordinary outranks either of them.
         */
        $this->assertSame(
            ['GQ-R1', 'GQ-R2', 'GQ-SC-1', 'GQ-SC-2', 'GQ-R3'],
            $references,
            'Two skipped statutory clients were not both reinserted ahead of the '
            .'ordinary client who arrived after them.'
        );
    }

    public function test_the_configured_priority_weights_actually_order_the_queue(): void
    {
        /*
         * The weights were dead configuration.
         *
         * priorityRank() returned a flat 0 for any verified claim and 1 for none,
         * so every statutory client tied and was separated only by arrival time.
         * config/govqueue.php promises that PWD and pregnant clients rank ahead of
         * senior citizens "because their circumstances are time-sensitive" - and
         * an administrator tuning those numbers saw no behaviour change at all.
         *
         * The existing test only asserted that the weights ARRAY had the
         * documented shape, which is true whether or not anything reads it.
         */
        $pwd = $this->ticket(
            AppointmentStatus::CheckedIn,
            '2026-10-04 08:30:00',
            ['pwd' => true, 'verified' => true],
            'GQ-PWD',
        );

        // Arrived ten minutes later than the PWD client, and is outranked by it.
        $senior = $this->ticket(
            AppointmentStatus::CheckedIn,
            '2026-10-04 08:20:00',
            ['senior' => true, 'verified' => true],
            'GQ-SEN',
        );

        $sorted = $this->service->sortCollection(collect([$senior, $pwd]));

        $this->assertSame(
            'GQ-PWD',
            $sorted->first()->reference_number,
            'A PWD client did not outrank a senior citizen who arrived earlier, '
            .'despite the configured weights saying it should.'
        );
    }

    public function test_changing_the_configured_weights_changes_the_order(): void
    {
        // The point of the previous test: that it responds to configuration.
        // Without this, a hard-coded order would pass it too.
        config([
            'govqueue.priority_weights.pwd' => 50,
            'govqueue.priority_weights.pregnant' => 50,
            'govqueue.priority_weights.senior_citizen' => 5,
        ]);

        $pwd = $this->ticket(
            AppointmentStatus::CheckedIn,
            '2026-10-04 08:30:00',
            ['pwd' => true, 'verified' => true],
            'GQ-PWD',
        );

        $senior = $this->ticket(
            AppointmentStatus::CheckedIn,
            '2026-10-04 08:20:00',
            ['senior' => true, 'verified' => true],
            'GQ-SEN',
        );

        $sorted = $this->service->sortCollection(collect([$pwd, $senior]));

        $this->assertSame(
            'GQ-SEN',
            $sorted->first()->reference_number,
            'Reordering the configured weights did not change who is served first.'
        );
    }

    public function test_a_client_claiming_several_categories_takes_the_strongest(): void
    {
        // A senior citizen who is also a PWD client must rank as a PWD client,
        // not as whichever category happened to be checked first.
        $senior = $this->ticket(
            AppointmentStatus::CheckedIn,
            '2026-10-04 08:20:00',
            ['senior' => true, 'verified' => true],
            'GQ-SEN',
        );

        $both = $this->ticket(
            AppointmentStatus::CheckedIn,
            '2026-10-04 08:30:00',
            ['senior' => true, 'pwd' => true, 'verified' => true],
            'GQ-BOTH',
        );

        $sorted = $this->service->sortCollection(collect([$senior, $both]));

        $this->assertSame(
            'GQ-BOTH',
            $sorted->first()->reference_number,
            'A client holding two categories did not rank on the stronger one.'
        );
    }

    public function test_the_reinsert_depth_is_configurable(): void
    {
        config(['govqueue.priority_skip_reinsert_after' => 1]);

        $senior = $this->ticket(
            AppointmentStatus::Skipped,
            '2026-10-04 08:00:00',
            ['senior' => true, 'verified' => true],
            'GQ-SC',
        );

        $regulars = collect(range(1, 4))->map(fn ($i) => $this->ticket(
            AppointmentStatus::CheckedIn,
            sprintf('2026-10-04 08:%02d:00', $i * 5),
            reference: 'GQ-R'.$i,
        ));

        $sorted = $this->service->sortCollection($regulars->push($senior));

        $this->assertSame('GQ-SC', $sorted[1]->reference_number);
    }

    public function test_zero_reinsert_depth_restores_the_old_behaviour(): void
    {
        config(['govqueue.priority_skip_reinsert_after' => 0]);

        $senior = $this->ticket(
            AppointmentStatus::Skipped,
            '2026-10-04 08:00:00',
            ['senior' => true, 'verified' => true],
            'GQ-SC',
        );

        $regulars = collect(range(1, 3))->map(fn ($i) => $this->ticket(
            AppointmentStatus::CheckedIn,
            sprintf('2026-10-04 08:%02d:00', $i * 5),
            reference: 'GQ-R'.$i,
        ));

        $sorted = $this->service->sortCollection($regulars->push($senior));

        $this->assertSame('GQ-SC', $sorted->last()->reference_number);
    }

    public function test_a_priority_client_already_near_the_front_is_left_alone(): void
    {
        $senior = $this->ticket(
            AppointmentStatus::CheckedIn,
            '2026-10-04 08:00:00',
            ['senior' => true, 'verified' => true],
            'GQ-SC',
        );

        $regular = $this->ticket(AppointmentStatus::CheckedIn, '2026-10-04 08:05:00', reference: 'GQ-R1');

        $sorted = $this->service->sortCollection(collect([$regular, $senior]));

        $this->assertSame('GQ-SC', $sorted[0]->reference_number);
    }

    /* ------------------------------------------------------------------ */
    /* Window service time falls back to its own services */
    /* ------------------------------------------------------------------ */

    public function test_a_window_average_that_disagrees_with_its_services_is_not_trusted(): void
    {
        // Window 2 was configured at 15 minutes while its services take 20 and
        // 30, so every wait estimate it showed was about half the real wait.
        $window = ServiceWindow::factory()->create(['avg_service_minutes' => 15]);

        Service::factory()->create(['service_window_id' => $window->id, 'duration_minutes' => 20]);
        Service::factory()->create(['service_window_id' => $window->id, 'duration_minutes' => 30]);

        $this->assertEqualsWithDelta(
            25.0,
            app(QueueReaderService::class)->derivedAverageMinutes($window->fresh()),
            0.01,
        );
    }

    public function test_a_configured_average_that_agrees_with_its_services_is_used(): void
    {
        $window = ServiceWindow::factory()->create(['avg_service_minutes' => 15]);

        Service::factory()->create(['service_window_id' => $window->id, 'duration_minutes' => 15]);
        Service::factory()->create(['service_window_id' => $window->id, 'duration_minutes' => 14]);

        $this->assertSame(
            15.0,
            app(QueueReaderService::class)->derivedAverageMinutes($window->fresh()),
        );
    }

    public function test_a_window_with_no_services_keeps_its_configured_average(): void
    {
        $window = ServiceWindow::factory()->create(['avg_service_minutes' => 12]);

        $this->assertSame(
            12.0,
            app(QueueReaderService::class)->derivedAverageMinutes($window->fresh()),
        );
    }

    public function test_priority_ranks_map_to_the_documented_weights(): void
    {
        $weights = $this->service->priorityWeights();

        $this->assertArrayHasKey('pwd', $weights);
        $this->assertArrayHasKey('pregnant', $weights);
        $this->assertArrayHasKey('senior_citizen', $weights);

        $this->assertLessThan(
            $weights['senior_citizen'],
            $weights['pwd'],
            'PWD clients rank ahead of senior citizens per config/govqueue.php.'
        );
    }
}
