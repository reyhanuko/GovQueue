<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ServiceWindow;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff and administrator account management.
 *
 * The rules that matter here are all about not locking the office out of its own
 * system. An administrator who can deactivate themselves, or demote the only
 * other administrator, can leave a deployment where nobody can reach the
 * configuration screens or the staff console - and the only way back would be
 * direct database surgery.
 */
class AdminStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1', 'name' => 'Window 1']);

        $this->admin = User::factory()->admin()->create([
            'email' => 'admin@govqueue.test',
            'name' => 'Primary Admin',
        ]);
    }

    /** A second active administrator, so "last admin" guards do not fire. */
    private function secondAdmin(): User
    {
        return User::factory()->admin()->create(['name' => 'Backup Admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Staffer',
            'email' => 'new.staffer@govqueue.test',
            'role' => 'staff',
            'service_window_id' => $this->window->id,
            'password' => 'temporary-pass',
            'password_confirmation' => 'temporary-pass',
            'is_active' => '1',
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* Creating */
    /* ------------------------------------------------------------------ */

    public function test_an_administrator_can_create_a_staff_account(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/staff', $this->payload())
            ->assertRedirect(route('admin.staff.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'new.staffer@govqueue.test',
            'role' => 'staff',
            'service_window_id' => $this->window->id,
            'is_active' => true,
        ]);
    }

    public function test_the_new_password_is_hashed_not_stored(): void
    {
        $this->actingAs($this->admin)->post('/admin/staff', $this->payload());

        $user = User::where('email', 'new.staffer@govqueue.test')->firstOrFail();

        $this->assertNotSame('temporary-pass', $user->password);
        $this->assertTrue(Hash::check('temporary-pass', $user->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/staff', $this->payload([
                'password' => 'short',
                'password_confirmation' => 'short',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'new.staffer@govqueue.test']);
    }

    public function test_mismatched_password_confirmation_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/staff', $this->payload(['password_confirmation' => 'different']))
            ->assertSessionHasErrors('password');
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/staff', $this->payload(['email' => $this->admin->email]))
            ->assertSessionHasErrors('email');
    }

    public function test_a_client_cannot_be_created_from_the_staff_screen(): void
    {
        // Clients self-register; this screen only covers operating accounts.
        $this->actingAs($this->admin)
            ->post('/admin/staff', $this->payload(['role' => 'client']))
            ->assertSessionHasErrors('role');
    }

    public function test_a_staff_account_cannot_be_pointed_at_a_missing_window(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/staff', $this->payload(['service_window_id' => 99999]))
            ->assertSessionHasErrors('service_window_id');
    }

    /* ------------------------------------------------------------------ */
    /* Editing */
    /* ------------------------------------------------------------------ */

    public function test_an_administrator_can_edit_a_staff_account(): void
    {
        $staffer = User::factory()->staff()->create([
            'email' => 'staffer@govqueue.test',
            'service_window_id' => $this->window->id,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$staffer->id}", $this->payload([
                'name' => 'Renamed Staffer',
                'email' => 'staffer@govqueue.test',
                'role' => 'staff',
                'service_window_id' => $this->window->id,
                'is_active' => '1',
            ]))
            ->assertRedirect(route('admin.staff.index'));

        $this->assertSame('Renamed Staffer', $staffer->fresh()->name);
    }

    public function test_leaving_the_password_blank_keeps_the_existing_one(): void
    {
        $staffer = User::factory()->staff()->create(['email' => 'staffer@govqueue.test']);

        $before = $staffer->password;

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$staffer->id}", [
                'name' => 'Staffer',
                'email' => 'staffer@govqueue.test',
                'role' => 'staff',
                'is_active' => '1',
                // No password fields at all, as the form sends when left empty.
            ])
            ->assertRedirect(route('admin.staff.index'));

        $this->assertSame($before, $staffer->fresh()->password);
        $this->assertTrue(Hash::check('password', $staffer->fresh()->password));
    }

    public function test_an_unchanged_email_does_not_trip_the_unique_check(): void
    {
        // The uniqueness rule has to exclude the account being edited, or saving
        // an unchanged email fails against the account's own row.
        $staffer = User::factory()->staff()->create(['email' => 'staffer@govqueue.test']);

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$staffer->id}", [
                'name' => 'Staffer Renamed',
                'email' => 'staffer@govqueue.test',
                'role' => 'staff',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.staff.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Staffer Renamed', $staffer->fresh()->name);
    }

    public function test_a_password_can_be_reset_deliberately(): void
    {
        $staffer = User::factory()->staff()->create(['email' => 'staffer@govqueue.test']);

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$staffer->id}", [
                'name' => 'Staffer',
                'email' => 'staffer@govqueue.test',
                'role' => 'staff',
                'is_active' => '1',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])
            ->assertRedirect(route('admin.staff.index'));

        $this->assertTrue(Hash::check('brand-new-pass', $staffer->fresh()->password));
    }

    /* ------------------------------------------------------------------ */
    /* Not locking the office out */
    /* ------------------------------------------------------------------ */

    public function test_the_last_administrator_cannot_be_demoted(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->put("/admin/staff/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => 'staff',
                'is_active' => '1',
            ])
            ->assertSessionHas('error');

        $this->assertSame('admin', $this->admin->fresh()->role->value);
    }

    public function test_the_last_administrator_cannot_be_deactivated_by_editing(): void
    {
        // Note the checkbox is simply absent when unchecked, so this is exactly
        // what the form sends.
        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->put("/admin/staff/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => 'admin',
            ])
            ->assertSessionHas('error');

        $this->assertTrue((bool) $this->admin->fresh()->is_active);
    }

    public function test_the_last_administrator_cannot_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->put("/admin/staff/{$this->admin->id}/active", [])
            ->assertSessionHas('error');

        $this->assertTrue((bool) $this->admin->fresh()->is_active);
    }

    public function test_the_last_administrator_cannot_delete_themselves(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->delete("/admin/staff/{$this->admin->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_an_administrator_cannot_reset_their_own_password(): void
    {
        $before = $this->admin->password;

        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->put("/admin/staff/{$this->admin->id}/password", [
                'password' => 'self-chosen-pass',
                'password_confirmation' => 'self-chosen-pass',
            ])
            ->assertSessionHas('error');

        $this->assertSame($before, $this->admin->fresh()->password);
    }

    public function test_an_administrator_can_be_demoted_once_another_exists(): void
    {
        // Otherwise the guard would be "never demote anyone", which is not the
        // rule: the rule is "never leave none".
        $this->secondAdmin();

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => 'staff',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.staff.index'))
            ->assertSessionHas('success');

        $this->assertSame('staff', $this->admin->fresh()->role->value);
    }

    public function test_a_refused_demotion_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->put("/admin/staff/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => 'staff',
                'is_active' => '1',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.change_blocked',
            'subject_type' => 'User',
            'subject_id' => $this->admin->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Activating and deactivating */
    /* ------------------------------------------------------------------ */

    public function test_a_staff_account_can_be_deactivated_and_reactivated(): void
    {
        $staffer = User::factory()->staff()->create([
            'email' => 'staffer@govqueue.test',
            'service_window_id' => $this->window->id,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->put("/admin/staff/{$staffer->id}/active", [])
            ->assertSessionHas('success');

        $this->assertFalse((bool) $staffer->fresh()->is_active);

        $this->actingAs($this->admin)
            ->from(route('admin.staff.index'))
            ->put("/admin/staff/{$staffer->id}/active", [])
            ->assertSessionHas('success');

        $this->assertTrue((bool) $staffer->fresh()->is_active);
    }

    public function test_deactivating_staff_keeps_their_history(): void
    {
        $staffer = User::factory()->staff()->create([
            'email' => 'staffer@govqueue.test',
            'service_window_id' => $this->window->id,
        ]);

        // transactions.handled_by is one of the references that makes deletion
        // unsafe: removing the account would blank out who actually served the
        // client.
        $appointment = Appointment::factory()->create(['status' => 'completed']);

        $transaction = Transaction::create([
            'reference_number' => 'TXN-PROBE-001',
            'appointment_id' => $appointment->id,
            'service_id' => $appointment->service_id,
            'user_id' => $appointment->user_id,
            'handled_by' => $staffer->id,
            'fee_paid' => 50,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$staffer->id}/active", []);

        $this->assertFalse((bool) $staffer->fresh()->is_active);

        // The account and the record of its work both survive.
        $this->assertDatabaseHas('users', ['id' => $staffer->id]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'handled_by' => $staffer->id,
        ]);
    }

    public function test_a_deactivated_staffer_with_a_live_session_is_logged_out(): void
    {
        // The account was deactivated after this session was established, so the
        // `active` middleware has to end the session rather than trust it.
        $staffer = User::factory()->staff()->create([
            'email' => 'staffer@govqueue.test',
            'service_window_id' => $this->window->id,
        ]);

        $this->actingAs($staffer)->get('/staff')->assertOk();

        $staffer->update(['is_active' => false]);

        $this->actingAs($staffer)
            ->get('/staff')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* Screen */
    /* ------------------------------------------------------------------ */

    public function test_the_staff_screen_loads(): void
    {
        $this->actingAs($this->admin)->get('/admin/staff')->assertOk()->assertSee('Staff accounts');
    }

    public function test_the_staff_screen_warns_when_only_one_administrator_remains(): void
    {
        User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->actingAs($this->admin)
            ->get('/admin/staff')
            ->assertOk()
            ->assertSee('only active administrator');
    }

    public function test_the_staff_screen_does_not_warn_when_a_second_admin_exists(): void
    {
        $this->secondAdmin();

        $this->actingAs($this->admin)
            ->get('/admin/staff')
            ->assertOk()
            ->assertDontSee('only active administrator');
    }

    public function test_the_staff_screen_does_not_list_clients(): void
    {
        $client = User::factory()->client()->create(['name' => 'Walkup Client']);

        $this->actingAs($this->admin)
            ->get('/admin/staff')
            ->assertOk()
            ->assertDontSee($client->email);
    }

    public function test_the_staff_screen_filters_by_role_and_search(): void
    {
        User::factory()->staff()->create([
            'name' => 'Filterable Staffer',
            'email' => 'filterable@govqueue.test',
            'service_window_id' => $this->window->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/staff?q=Filterable')
            ->assertOk()
            ->assertSee('filterable@govqueue.test')
            ->assertDontSee($this->admin->email);

        $this->actingAs($this->admin)
            ->get('/admin/staff?role=admin')
            ->assertOk()
            ->assertSee($this->admin->email)
            ->assertDontSee('filterable@govqueue.test');
    }
}
