<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * History pruning.
 *
 * Every booking writes an audit entry, a status history row and a notification log
 * row, and nothing removed one. The first version of this command assumed orphaned
 * rows simply accumulated and pruned on age plus a missing appointment - which the
 * migrations show is wrong for two of the three tables:
 *
 *  - queue_status_histories.appointment_id is non-nullable ON DELETE CASCADE, so an
 *    orphan cannot exist there at all.
 *  - notification_logs.appointment_id is nullable ON DELETE SET NULL, so deleting
 *    an appointment leaves the row behind with a null appointment_id.
 *  - audit_logs has no foreign key on its polymorphic subject pair, so those rows
 *    are never cleaned up by anything.
 *
 * The tests are mostly about what must survive. A retention job that trims a live
 * appointment's trail would destroy the record of what happened to a client, and
 * one that ages out refused admin actions would erase the evidence an audit trail
 * exists to surface.
 */
class PruneHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create(['code' => 'W1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    public function test_it_reports_nothing_to_do_on_a_clean_database(): void
    {
        $this->artisan('govqueue:prune-history', ['--days' => 365, '--force' => true])
            ->expectsOutputToContain('Nothing to prune')
            ->assertSuccessful();
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->seedOrphanedRows();

        $this->artisan('govqueue:prune-history', ['--days' => 365, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(1, NotificationLog::count());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_it_removes_a_notification_log_whose_appointment_is_gone(): void
    {
        // ON DELETE SET NULL leaves this row behind, holding a client's email
        // address and the subject line of a booking that no longer exists.
        $this->seedOrphanedRows();

        $this->artisan('govqueue:prune-history', ['--days' => 365, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_it_removes_an_audit_entry_whose_subject_is_gone(): void
    {
        $this->seedOrphanedRows();

        $this->artisan('govqueue:prune-history', ['--days' => 365, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_it_never_removes_a_notification_for_a_live_appointment(): void
    {
        $appointment = $this->seedAppointment();
        $this->seedRowsFor($appointment, old: true);

        $this->artisan('govqueue:prune-history', ['--days' => 1, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(
            1,
            NotificationLog::count(),
            'A notification for a live appointment was pruned.'
        );
    }

    public function test_it_never_removes_an_audit_entry_for_a_live_subject(): void
    {
        // The failure that matters most. An appointment from three years ago whose
        // record still exists must keep its trail, or the audit screen starts lying
        // about what happened.
        $appointment = $this->seedAppointment();
        $this->seedRowsFor($appointment, old: true);

        $this->artisan('govqueue:prune-history', ['--days' => 1, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, AuditLog::count(), 'A live subject lost its audit entry.');
        $this->assertSame(1, NotificationLog::count());
    }

    public function test_it_keeps_audit_entries_about_live_configuration(): void
    {
        // Configuration subjects are kept by the same rule as appointments: the
        // window still exists, so the record of who changed it still means something.
        $template = SlotTemplate::create([
            'service_id' => $this->service->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        $audit = AuditLog::create([
            'action' => 'slot_template.created',
            'subject_type' => SlotTemplate::class,
            'subject_id' => $template->id,
        ]);

        $this->backdate($audit, now()->subYears(3));

        $this->artisan('govqueue:prune-history', ['--days' => 30, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, AuditLog::count());
    }

    public function test_it_removes_an_audit_entry_whose_template_was_deleted(): void
    {
        // The realistic orphaned case: a template was deactivated, deleted, and its
        // audit entry stayed behind because there is no foreign key to clean it up.
        $template = SlotTemplate::create([
            'service_id' => $this->service->id,
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        // Backdated after creation: created_at is not in $fillable, so passing it to
        // create() is silently dropped and the row would look brand new.
        $audit = AuditLog::create([
            'action' => 'slot_template.deleted',
            'subject_type' => SlotTemplate::class,
            'subject_id' => $template->id,
        ]);

        $this->backdate($audit, now()->subYears(2));

        $template->delete();

        $this->artisan('govqueue:prune-history', ['--days' => 365, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_it_never_removes_an_appointment(): void
    {
        $old = $this->seedAppointment();
        $this->seedRowsFor($old, old: true);

        $this->artisan('govqueue:prune-history', ['--days' => 1, '--force' => true])
            ->assertSuccessful();

        $this->assertNotNull(
            Appointment::find($old->id),
            'Pruning deleted an appointment. The operational record is not history.'
        );
    }

    public function test_refused_admin_actions_survive_forever(): void
    {
        // Someone repeatedly trying to demote the last administrator, or delete a
        // service clients have booked, is exactly what an audit trail exists to
        // reveal. Those entries must not age out even with no subject at all.
        foreach (['user.change_blocked', 'service.delete_blocked', 'slot_template.rejected'] as $action) {
            $entry = AuditLog::create([
                'action' => $action,
                'payload' => ['reason' => 'test'],
            ]);

            $this->backdate($entry, now()->subYears(5));
        }

        $this->artisan('govqueue:prune-history', ['--days' => 30, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(
            3,
            AuditLog::count(),
            'Refused actions were pruned. That is the evidence an auditor looks for.'
        );
    }

    public function test_failed_sign_ins_survive_forever(): void
    {
        // A password-guessing run against one account is invisible without these.
        $entry = AuditLog::create([
            'action' => 'auth.login_failed',
            'payload' => ['email' => 'someone@example.test', 'reason' => 'bad_credentials'],
        ]);

        $this->backdate($entry, now()->subYears(3));

        $this->artisan('govqueue:prune-history', ['--days' => 30, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, AuditLog::count());
    }

    public function test_rows_inside_the_retention_window_are_kept(): void
    {
        $this->seedOrphanedRows();

        $this->artisan('govqueue:prune-history', ['--days' => 3650, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, NotificationLog::count(), 'A 10 year window should keep 2 year old rows.');
        $this->assertSame(1, AuditLog::count());
    }

    public function test_the_retention_window_is_configurable(): void
    {
        $this->seedOrphanedRows();

        $this->artisan('govqueue:prune-history', ['--days' => 90, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_the_reported_count_matches_what_is_removed(): void
    {
        // The command prints a per-table count before deleting. If the counting
        // query and the delete query ever drift, the operator is told one number
        // and another happens - worse than no report at all.
        $this->seedOrphanedRows();

        $this->artisan('govqueue:prune-history', ['--days' => 365, '--force' => true])
            ->expectsOutputToContain('Pruned 2 rows')
            ->assertSuccessful();

        $this->assertSame(0, NotificationLog::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_it_deletes_when_the_operator_confirms(): void
    {
        $this->seedOrphanedRows();

        // The interactive path a cautious operator gets. Defaulted to false so a
        // stray Enter deletes nothing.
        $this->artisan('govqueue:prune-history', ['--days' => 365])
            ->expectsConfirmation('Delete these rows? This cannot be undone.', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, NotificationLog::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_it_declines_when_the_operator_says_no(): void
    {
        $this->seedOrphanedRows();

        $this->artisan('govqueue:prune-history', ['--days' => 365])
            ->expectsConfirmation('Delete these rows? This cannot be undone.', 'no')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertSuccessful();

        $this->assertSame(1, NotificationLog::count());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_the_prune_index_exists_on_audit_logs(): void
    {
        // Without an index on (action, created_at) the prune is a full scan of a
        // table that only grows - the longer the deployment runs, the slower the
        // maintenance job meant to keep it manageable.
        $indexes = Schema::getIndexes('audit_logs');

        $this->assertTrue(
            collect($indexes)->contains(fn ($i) => $i['name'] === 'audit_logs_action_created_index'),
            'The pruning index is missing from audit_logs.'
        );
    }

    /* ------------------------------------------------------------------ */

    private function seedAppointment(string $queueNumber = 'A-001'): Appointment
    {
        $start = now()->addDay()->setTime(9, 0);

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 5,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::create([
            'reference_number' => 'GQ-PR-'.uniqid(),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => 'completed',
            'queue_number' => $queueNumber,
            'queue_date' => $start->toDateString(),
            'created_at' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ]);
    }

    /**
     * One notification log and one audit entry, both describing a subject that no
     * longer exists, and both old enough to fall outside the default window.
     */
    /**
     * One notification log and one audit entry, both describing a subject that no
     * longer exists, and both old enough to fall outside the default window.
     *
     * Timestamps are set after creation rather than passed to create(). Neither
     * model lists created_at in $fillable, so passing it in is silently dropped and
     * the row is stamped with the current time - which made the prune report
     * nothing to do and nearly hid a real bug in the cutoff logic.
     */
    private function seedOrphanedRows(): void
    {
        $old = now()->subYears(2);

        $notification = NotificationLog::create([
            'appointment_id' => null,
            'user_id' => null,
            'channel' => 'email',
            'type' => 'confirmation',
            'status' => 'sent',
            'destination' => 'gone@example.test',
        ]);

        // subject_id points at an id nothing uses. The audit table has no foreign
        // key here, which is exactly why the row outlived its subject.
        $audit = AuditLog::create([
            'action' => 'appointment.booked',
            'subject_type' => Appointment::class,
            'subject_id' => 999_999,
        ]);

        $this->backdate($notification, $old);
        $this->backdate($audit, $old);
    }

    private function backdate(Model $model, Carbon $when): void
    {
        $model->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
    }

    private function seedRowsFor(Appointment $appointment, bool $old): void
    {
        $when = $old ? now()->subYears(2) : now();

        $notification = NotificationLog::create([
            'appointment_id' => $appointment->id,
            'user_id' => $appointment->user_id,
            'channel' => 'email',
            'type' => 'confirmation',
            'status' => 'sent',
        ]);

        $audit = AuditLog::create([
            'action' => 'appointment.booked',
            'subject_type' => Appointment::class,
            'subject_id' => $appointment->id,
        ]);

        $this->backdate($notification, $when);
        $this->backdate($audit, $when);
    }
}
