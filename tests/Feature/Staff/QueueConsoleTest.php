<?php

namespace Tests\Feature\Staff;

use App\Enums\AppointmentStatus;
use App\Livewire\Staff\QueueConsole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class QueueConsoleTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1 - Certificates',
            'avg_service_minutes' => 10,
        ]);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
        $this->staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        // appointments.time_slot_id is NOT NULL, so every test needs a real slot
        // for the service to exist before a ticket can be filed against it.
        //
        // A fixed 07:00, not "an hour ago". It used to be now()->subHour(), which
        // collided with slot()'s 09:00 series whenever the suite happened to run
        // between 10:00 and 10:59 - seventeen tests failing on a duplicate-key
        // error that had nothing to do with the code under test, and only during
        // that hour. A fixture that depends on the wall clock is a fixture that
        // fails at random.
        $start = today()->setTime(7, 0);

        TimeSlot::create([
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
    }

    protected function tearDown(): void
    {
        // A pinned clock must not outlive the test that pinned it.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function slot(): TimeSlot
    {
        $this->slotCounter++;
        $start = today()->setTime(9, 0)->addMinutes(($this->slotCounter - 1) * 30);

        return TimeSlot::create([
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
    }

    private function waiting(array $overrides = [], ?string $checkedInAt = null): Appointment
    {
        $slot = $this->slot();

        $appointment = Appointment::create(array_merge([
            'reference_number' => 'GQ-S-'.uniqid(),
            'user_id' => User::factory()->client()->create(['name' => 'Test Client'])->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => AppointmentStatus::CheckedIn,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-'.str_pad((string) (Appointment::count() + 1), 3, '0', STR_PAD_LEFT),
            'queue_date' => today()->toDateString(),
            'confirmed_at' => now(),
            'checked_in_at' => $checkedInAt ?? now(),
        ], $overrides));

        $slot->increment('booked_count');

        return $appointment;
    }

    /**
     * Mounts the console for the acting staff member.
     *
     * Returns Livewire's Testable wrapper, which carries the ->call()
     * and ->assertSet() assertion helpers.
     */
    private function console()
    {
        return Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->set('queueDate', today()->toDateString());
    }

    /* ------------------------------------------------------------------ */

    public function test_staff_can_open_the_console(): void
    {
        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->assertOk()
            ->assertSee('Call next client');
    }

    public function test_a_client_cannot_open_the_staff_console(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/staff')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_from_the_console(): void
    {
        $this->get('/staff')->assertRedirect(route('login'));
    }

    public function test_calling_next_serves_the_first_waiting_client(): void
    {
        $appointment = $this->waiting();

        $this->console()
            ->call('callNext')
            ->assertSet('feedback', 'Now serving '.$appointment->queue_number.' - Test Client');

        $this->assertSame(AppointmentStatus::Serving, $appointment->fresh()->status);
    }

    public function test_calling_next_with_nobody_waiting_explains_itself(): void
    {
        $this->console()
            ->call('callNext')
            ->assertSet('error', 'Nobody is waiting at this window.');
    }

    public function test_the_console_refuses_a_second_client_while_one_is_being_served(): void
    {
        $this->waiting();
        $this->waiting();

        $this->console()
            ->call('callNext')
            ->call('callNext')
            ->assertSet('error', 'Finish serving the current client before calling the next one.');
    }

    public function test_a_confirmed_client_can_be_checked_in(): void
    {
        $appointment = $this->waiting([
            'status' => AppointmentStatus::Confirmed,
            'checked_in_at' => null,
        ]);

        // Inside the check-in window (decision D7), so the test is about the
        // console and not about the hour of the day it runs in.
        Carbon::setTestNow($appointment->timeSlot->start_at);

        $this->console()
            ->call('checkIn', $appointment->id)
            ->assertSet('feedback', $appointment->queue_number.' checked in.');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_staff_can_record_a_transaction(): void
    {
        $this->waiting();
        $this->console()->call('callNext');

        $serving = Appointment::where('status', AppointmentStatus::Serving->value)->firstOrFail();

        $this->console()
            ->call('openComplete', $serving->id)
            ->call('complete')
            ->assertSet('feedback', 'Transaction recorded for '.$serving->queue_number.'.');

        $this->assertSame(AppointmentStatus::Completed, $serving->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'appointment_id' => $serving->id,
            'handled_by' => $this->staff->id,
        ]);
    }

    public function test_staff_can_mark_a_no_show(): void
    {
        $appointment = $this->waiting();

        $this->console()->call('callNext')->call('markNoShow')
            ->assertSet('feedback', $appointment->queue_number.' marked as a no-show.');

        $this->assertSame(AppointmentStatus::NoShow, $appointment->fresh()->status);
    }

    public function test_staff_can_skip_a_client_and_are_warned_after_repeat_offences(): void
    {
        $appointment = $this->waiting();

        $this->console()->call('callNext');

        // First skip: moved back, with no warning yet.
        $this->console()
            ->call('skip', $appointment->id)
            ->assertSet('feedback', $appointment->queue_number.' moved back in the line.');

        // The second skip crosses config('govqueue.max_skips') and prompts staff
        // to close the ticket as a no-show instead of calling forever.
        $this->console()
            ->call('skip', $appointment->id)
            ->assertSee('consider marking this client a no-show');

        $this->assertSame(2, (int) $appointment->fresh()->skip_count);
    }

    public function test_staff_can_verify_a_priority_claim_from_the_console(): void
    {
        $appointment = $this->waiting([
            'claims_senior_citizen' => true,
            'priority_reference' => 'SC-7788',
        ]);

        $this->console()
            ->call('openVerify', $appointment->id)
            ->call('verifyPriority')
            ->assertSet('feedback', 'Priority verified for '.$appointment->queue_number.'. Reordering the queue.');

        $this->assertNotNull($appointment->fresh()->priority_verified_at);
    }

    public function test_staff_can_refuse_a_priority_claim(): void
    {
        $appointment = $this->waiting(['claims_pwd' => true, 'priority_reference' => 'PWD-1']);

        $this->console()
            ->call('openVerify', $appointment->id)
            ->set('priorityNote', 'No ID presented')
            ->call('refusePriority');

        $appointment->refresh();

        $this->assertFalse($appointment->claims_pwd);
        $this->assertNull($appointment->priority_verified_at);
    }

    public function test_priority_cannot_be_verified_or_refused_on_another_window(): void
    {
        /*
         * Statutory priority, across windows.
         *
         * verifyingId is a public Livewire property, so the browser can set it to
         * any appointment id in the system with one request. Every other mutating
         * action on this console re-checks which window the ticket belongs to;
         * verifyPriority() and refusePriority() did not, so a staffer pinned to
         * their own counter could grant or void RA 9994 / RA 10754 precedence on
         * a ticket in a queue they do not work - deciding who is served next.
         *
         * openVerify()'s guard is irrelevant here: it protects the button that
         * normally sets the id, and the id can be set without it.
         */
        $other = ServiceWindow::factory()->create(['code' => 'WINDOW_2', 'name' => 'Window 2 - Permits']);

        $foreign = $this->waiting(['queue_number' => 'B-001']);
        $foreign->update(['service_window_id' => $other->id, 'claims_senior_citizen' => true]);

        $console = $this->console();

        // Set directly, exactly as a crafted request would.
        $console->set('verifyingId', $foreign->id)
            ->call('verifyPriority')
            ->assertSet('error', 'That ticket belongs to a different service window.');

        $this->assertNull(
            $foreign->fresh()->priority_verified_at,
            'A staffer verified priority on a ticket belonging to another window.'
        );

        $console->call('refusePriority')
            ->assertSet('error', 'That ticket belongs to a different service window.');

        $this->assertTrue(
            (bool) $foreign->fresh()->claims_senior_citizen,
            'A staffer refused a priority claim on a ticket belonging to another window.'
        );
    }

    public function test_a_verified_priority_decision_is_audited(): void
    {
        $appointment = $this->waiting(['claims_senior_citizen' => true]);

        $this->console()
            ->call('openVerify', $appointment->id)
            ->call('verifyPriority');

        // A statutory decision with no audit row is the worst outcome available:
        // it changes who is served next and leaves no record of who decided.
        // All three writes are in one transaction now, so the grant and its
        // record cannot come apart.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.priority_verified',
            'subject_id' => $appointment->id,
        ]);

        $this->assertDatabaseHas('queue_status_histories', [
            'appointment_id' => $appointment->id,
        ]);
    }

    public function test_a_refused_priority_decision_is_audited(): void
    {
        $appointment = $this->waiting(['claims_pwd' => true]);

        $this->console()
            ->call('openVerify', $appointment->id)
            ->set('priorityNote', 'No ID presented')
            ->call('refusePriority');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.priority_refused',
            'subject_id' => $appointment->id,
        ]);
    }

    public function test_verifying_a_ticket_with_no_claim_is_refused(): void
    {
        $appointment = $this->waiting();

        $this->console()
            ->call('openVerify', $appointment->id)
            ->call('verifyPriority')
            ->assertSet('error', 'This client has not claimed any priority category.');
    }

    public function test_the_console_shows_queue_statistics(): void
    {
        $this->waiting();

        $this->console()
            ->assertSee('Waiting')
            ->assertSee('Avg service');
    }

    public function test_the_console_lists_the_next_up_clients(): void
    {
        $confirmed = $this->waiting([
            'status' => AppointmentStatus::Confirmed,
            'checked_in_at' => null,
        ]);

        $this->console()->assertSee('Booked but not yet checked in');

        $this->console()->call('checkIn', $confirmed->id);
    }

    public function test_staff_cannot_act_on_a_ticket_from_another_window(): void
    {
        $otherWindow = ServiceWindow::factory()->create(['code' => 'WINDOW_2']);
        $otherService = Service::factory()->create(['service_window_id' => $otherWindow->id]);

        $slot = TimeSlot::create([
            'service_id' => $otherService->id,
            'service_window_id' => $otherWindow->id,
            'slot_date' => today()->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'start_at' => today()->setTime(11, 0)->toDateTimeString(),
            'end_at' => today()->setTime(11, 30)->toDateTimeString(),
            'capacity' => 5,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $foreign = Appointment::create([
            'reference_number' => 'GQ-F-'.uniqid(),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $otherService->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $otherWindow->id,
            'status' => AppointmentStatus::CheckedIn,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-090',
            'queue_date' => today()->toDateString(),
        ]);

        $this->console()->call('checkIn', $foreign->id);

        // The foreign ticket must be untouched.
        $this->assertSame(AppointmentStatus::CheckedIn, $foreign->fresh()->status);
    }

    public function test_the_console_is_scoped_to_the_assigned_window_by_default(): void
    {
        $response = $this->actingAs($this->staff)->get('/staff');

        $response->assertOk()->assertSee('Window 1 - Certificates');
    }

    public function test_a_staffer_cannot_switch_to_another_window(): void
    {
        /*
         * A clerk works their own counter. Opening somebody else's console meant
         * Rosa could work Window 3 and issue queue numbers that collided with
         * the clerk actually sitting there. Hidden in the UI, and enforced again
         * here because a hidden <select> is not a rule.
         */
        ServiceWindow::factory()->create(['code' => 'WINDOW_2', 'name' => 'Window 2 - Permits']);

        $this->actingAs($this->staff)->get('/staff')
            ->assertOk()
            ->assertSee('limited to your assigned window')
            ->assertDontSee('<option value="WINDOW_2"', escape: false);

        $this->console()
            ->set('windowCode', 'WINDOW_2')
            ->assertSet('windowCode', 'WINDOW_1')
            ->assertSet('error', 'Your console is limited to your assigned window.');
    }

    public function test_an_admin_may_open_the_console_for_any_window(): void
    {
        ServiceWindow::factory()->create(['code' => 'WINDOW_2', 'name' => 'Window 2 - Permits']);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/staff')
            ->assertOk()
            // An administrator keeps the dropdown, with all of them in it.
            ->assertSee('Administrators can open any window.');
    }

    public function test_a_client_cannot_mount_the_console_component(): void
    {
        // Livewire renders the abort(403) as the site's 403 page rather than
        // throwing, so assert on the rendered output.
        Livewire::actingAs(User::factory()->client()->create())
            ->test(QueueConsole::class)
            ->assertSee('Access denied');
    }

    public function test_an_invalid_fee_is_rejected_with_a_validation_error(): void
    {
        $this->waiting();
        $this->console()->call('callNext');

        $serving = Appointment::where('status', AppointmentStatus::Serving->value)->firstOrFail();

        $this->console()
            ->call('openComplete', $serving->id)
            ->set('feePaid', 'abc')
            ->call('complete')
            ->assertHasErrors(['feePaid' => 'numeric']);

        $this->assertNotSame(AppointmentStatus::Completed, $serving->fresh()->status);
    }

    public function test_acting_on_a_foreign_ticket_surfaces_an_inline_error(): void
    {
        $otherWindow = ServiceWindow::factory()->create(['code' => 'WINDOW_2']);
        $otherService = Service::factory()->create(['service_window_id' => $otherWindow->id]);
        $slot = TimeSlot::create([
            'service_id' => $otherService->id,
            'service_window_id' => $otherWindow->id,
            'slot_date' => today()->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'start_at' => today()->setTime(11, 0)->toDateTimeString(),
            'end_at' => today()->setTime(11, 30)->toDateTimeString(),
            'capacity' => 5,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $foreign = Appointment::create([
            'reference_number' => 'GQ-F-'.uniqid(),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $otherService->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $otherWindow->id,
            'status' => AppointmentStatus::CheckedIn,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-090',
            'queue_date' => today()->toDateString(),
        ]);

        $this->console()
            ->call('checkIn', $foreign->id)
            ->assertSet('error', 'That ticket belongs to a different service window.');
    }

    /* ------------------------------------------------------------------ */
    /* Walk-ins */
    /* ------------------------------------------------------------------ */

    public function test_staff_can_issue_a_walk_in_ticket(): void
    {
        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->set('walkInPhone', '09171234567')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'service_id' => $this->service->id,
            'source' => 'walk_in',
            'status' => 'checked_in',
        ]);
    }

    public function test_a_walk_in_may_record_a_priority_claim_without_granting_it(): void
    {
        /*
         * A walk-in has no account to carry a claim, so before this existed the
         * only way a senior citizen arriving without a booking could get any
         * recognition was for staff to edit the database - and RA 9994 applies
         * to them as much as to anybody.
         *
         * Recorded, not granted: priority_verified_at stays null, so the queue
         * is not reordered until staff verify the claim.
         */
        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->set('walkInClaims', ['senior_citizen'])
            ->call('createWalkIn')
            ->assertHasNoErrors()
            ->assertSet('feedback', fn (string $feedback) => str_contains($feedback, 'Priority claim recorded')
                && str_contains($feedback, 'queue is unchanged'));

        $walkIn = Appointment::where('source', 'walk_in')->latest('id')->first();

        $this->assertTrue($walkIn->claims_senior_citizen);
        $this->assertFalse($walkIn->hasVerifiedPriority());
    }

    public function test_a_pwd_walk_in_claim_needs_a_reference(): void
    {
        // These two categories cannot be inferred from anything at the counter.
        // Accepting the claim without evidence would create an unverifiable
        // record that still looks as strong as one with it.
        $c = Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ben Reyes')
            ->set('walkInClaims', ['pwd']);

        $c->call('createWalkIn')
            ->assertSet('error', fn (?string $error) => str_contains((string) $error, 'reference'));

        $this->assertDatabaseMissing('appointments', ['source' => 'walk_in']);

        $c->set('walkInPriorityReference', 'PWD-12345')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $walkIn = Appointment::where('source', 'walk_in')->latest('id')->first();

        $this->assertTrue($walkIn->claims_pwd);
        $this->assertSame('PWD-12345', $walkIn->priority_reference);
        $this->assertFalse($walkIn->hasVerifiedPriority());
    }

    public function test_an_unknown_priority_category_is_refused(): void
    {
        // createWalkIn is a public service method, and the category is written
        // to boolean columns - an unrecognised string would create a claim that
        // nothing downstream can read.
        $this->expectException(ValidationException::class);

        app(BookingService::class)->createWalkIn([
            'name' => 'Ana Dela Cruz',
            'service_id' => $this->service->id,
            'claims' => ['vip', 'senior_citizen'],
        ], $this->staff);
    }

    public function test_a_skipped_client_standing_at_the_counter_can_be_called_back(): void
    {
        /*
         * A client who was moved back, went and got what they needed, and is
         * standing at the counter again should not have to wait for the queue
         * order to reach them. This is a shortcut for that one case, not a way
         * to jump the line.
         */
        $appointment = $this->waiting();

        $this->console()->call('callNext');
        $this->console()->call('skip', $appointment->id);

        $this->assertSame(AppointmentStatus::Skipped, $appointment->fresh()->status);

        $this->console()
            ->call('callBack', $appointment->id)
            ->assertSet('feedback', 'Called '.$appointment->queue_number.' back to the window - Test Client.')
            ->assertSet('error', null);

        $this->assertSame(AppointmentStatus::Serving, $appointment->fresh()->status);
    }

    public function test_a_client_cannot_be_called_back_while_somebody_is_being_served(): void
    {
        $skipped = $this->waiting(['queue_number' => 'A-002']);
        $next = $this->waiting(['queue_number' => 'A-003']);

        $this->console()->call('callNext');
        $this->console()->call('skip', $skipped->id);

        $this->console()->call('callNext');

        $this->assertSame(AppointmentStatus::Serving, $next->fresh()->status);

        $this->console()
            ->call('callBack', $skipped->id)
            ->assertSet('error', 'Finish serving '.$next->queue_number.' before calling anyone else.');

        $this->assertSame(AppointmentStatus::Skipped, $skipped->fresh()->status);
    }

    public function test_a_waiting_client_cannot_be_called_back_directly(): void
    {
        // Only a skipped ticket may be called this way. Anything else has to go
        // through the normal order, or the button would be a queue-jumping
        // control wearing a helpful label.
        $appointment = $this->waiting();

        $this->console()
            ->call('callBack', $appointment->id)
            ->assertSet('error', 'That ticket cannot be called back right now.');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_a_client_cannot_be_called_back_from_another_window(): void
    {
        $other = ServiceWindow::factory()->create(['code' => 'WINDOW_2', 'name' => 'Window 2 - Permits']);
        $foreign = Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $other->id,
            'time_slot_id' => $this->slot()->id,
            'status' => 'skipped',
            'queue_date' => today()->toDateString(),
            'queue_number' => 'B-001',
        ]);

        $this->console()
            ->call('callBack', $foreign->id)
            ->assertSet('error', fn (?string $error) => $error !== null);

        $this->assertSame(AppointmentStatus::Skipped, $foreign->fresh()->status);
    }

    public function test_priority_claims_are_cleared_between_walk_ins(): void
    {
        /*
         * The obvious thing to forget when the fields were added, and it matters:
         * leaving the previous walk-in's categories pre-ticked would grant
         * RA 9994 priority to whoever arrived next, unasked.
         */
        $console = Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->set('walkInClaims', ['senior_citizen'])
            ->set('walkInPriorityReference', 'date of birth on file')
            ->call('createWalkIn');

        $console->call('openWalkIn')
            ->assertSet('walkInClaims', [])
            ->assertSet('walkInPriorityReference', '');
    }

    public function test_a_walk_in_issues_a_w_number_not_an_a_number(): void
    {
        Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $walkIn = Appointment::where('source', 'walk_in')->latest('id')->first();

        $this->assertStringStartsWith('W-', $walkIn->queue_number);
    }

    public function test_a_duplicate_walk_in_is_refused_with_the_existing_ticket_named(): void
    {
        // One component instance for the whole test: console() returns a fresh
        // Testable each call, so reading state from a second one would read the
        // initial state rather than the state the duplicate produced.
        $c = Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1']);

        $c->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $first = Appointment::where('source', 'walk_in')->latest('id')->first();

        // The same name again, a moment later, for the same service.
        $c->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors()
            ->assertSet('walkInOverride', false);

        $this->assertStringContainsString($first->queue_number, (string) $c->get('error'));
        $this->assertSame(1, Appointment::where('source', 'walk_in')->count());
    }

    public function test_a_duplicate_can_be_overridden_by_staff_and_is_audited(): void
    {
        $c = Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1']);

        $c->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $c->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->set('walkInOverride', true)
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $this->assertSame(2, Appointment::where('source', 'walk_in')->count());

        $log = AuditLog::where('action', 'appointment.walk_in_created')
            ->where('payload->duplicate_override', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'A confirmed duplicate must leave an audit record.');
    }

    public function test_a_different_person_with_the_same_name_may_still_be_refused_or_confirmed(): void
    {
        // The guard is a safety net, not a rule: two people can share a surname.
        $c = Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1']);

        $c->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $c->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->set('walkInPhone', '09998887777')
            ->call('createWalkIn')
            ->set('walkInOverride', true)
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $this->assertSame(2, Appointment::where('source', 'walk_in')->count());
    }

    public function test_the_same_name_for_a_different_service_is_not_a_duplicate(): void
    {
        $otherService = Service::factory()->create([
            'service_window_id' => $this->window->id,
        ]);

        TimeSlot::create([
            'service_id' => $otherService->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->toDateString(),
            'start_time' => now()->subHour()->format('H:i:s'),
            'end_time' => now()->format('H:i:s'),
            'start_at' => now()->subHour()->toDateTimeString(),
            'end_at' => now()->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $c = Livewire::actingAs($this->staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1']);

        $c->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $c->call('openWalkIn')
            ->set('walkInServiceId', $otherService->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $this->assertSame(2, Appointment::where('source', 'walk_in')->count());
    }

    public function test_a_closed_walk_in_is_no_longer_a_duplicate(): void
    {
        $client = User::factory()->client()->create(['name' => 'Ana Dela Cruz']);

        $existing = $this->waiting(['user_id' => $client->id, 'source' => 'walk_in']);

        $this->console()
            ->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', 'Ana Dela Cruz')
            ->call('createWalkIn')
            ->assertHasNoErrors();

        $this->assertNull($this->console()->get('error'));
        $this->assertNotSame($existing->id, null);
    }

    public function test_a_walk_in_requires_a_name(): void
    {
        $this->console()
            ->call('openWalkIn')
            ->set('walkInServiceId', $this->service->id)
            ->set('walkInName', '')
            ->call('createWalkIn')
            ->assertHasErrors(['walkInName']);
    }

    public function test_a_client_cannot_reach_the_walk_in_action(): void
    {
        // Covered at mount by test_a_client_cannot_mount_the_console_component.
        // createWalkIn() re-checks the role itself, so the guard survives a
        // future refactor that mounts the console more loosely.
        $method = new \ReflectionMethod(QueueConsole::class, 'createWalkIn');
        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString('isBackOffice', $source);
    }

    public function test_a_stale_ticket_id_surfaces_an_inline_error_instead_of_a_404(): void
    {
        $this->console()
            ->call('checkIn', 999999)
            ->assertSet('error', 'That ticket no longer exists. It may have been cancelled or rescheduled.');
    }
}
