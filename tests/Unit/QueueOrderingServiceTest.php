<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\QueueOrderingService;
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
