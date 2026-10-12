<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The forced password change for a newly issued staff account.
 *
 * An administrator types the first password and shares it by hand, so until it
 * is replaced the account is only as private as that note. These tests pin the
 * behaviour end to end: the flag is set, login routes to the change screen, and
 * the flag clears only once the password really changes.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'password' => Hash::make('password'),
        ]);
    }

    public function test_a_new_account_is_marked_as_needing_a_password_change(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/staff', [
                'name' => 'Rosa Mendoza',
                'email' => 'rosa@govqueue.test',
                'role' => 'staff',
                'password' => 'temporary-123',
                'password_confirmation' => 'temporary-123',
            ])
            ->assertRedirect(route('admin.staff.index'));

        $this->assertTrue(
            (bool) User::where('email', 'rosa@govqueue.test')->first()->must_change_password,
        );
    }

    public function test_signing_in_with_a_temporary_password_lands_on_the_change_screen(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('temporary-123'),
            'must_change_password' => true,
        ]);

        $response = $this->post('/login', [
            'email' => $staff->email,
            'password' => 'temporary-123',
        ]);

        $response->assertRedirect(route('auth.password.change'));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_the_change_screen_is_reachable_once_signed_in(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('temporary-123'),
            'must_change_password' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('auth.password.change'))
            ->assertOk()
            ->assertSee('Choose your own password');
    }

    public function test_changing_the_password_clears_the_flag(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('temporary-123'),
            'must_change_password' => true,
        ]);

        $this->actingAs($staff)
            ->post(route('auth.password.change.store'), [
                'current_password' => 'temporary-123',
                'password' => 'my-own-password',
                'password_confirmation' => 'my-own-password',
            ])
            ->assertRedirect(route('dashboard'));

        $staff->refresh();

        $this->assertFalse((bool) $staff->must_change_password);
        $this->assertTrue(Hash::check('my-own-password', $staff->password));
    }

    public function test_the_wrong_temporary_password_is_refused(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('temporary-123'),
            'must_change_password' => true,
        ]);

        $this->actingAs($staff)
            ->from(route('auth.password.change'))
            ->post(route('auth.password.change.store'), [
                'current_password' => 'not-it',
                'password' => 'my-own-password',
                'password_confirmation' => 'my-own-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue((bool) $staff->fresh()->must_change_password);
    }

    public function test_the_new_password_must_differ_from_the_temporary_one(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('temporary-123'),
            'must_change_password' => true,
        ]);

        $this->actingAs($staff)
            ->from(route('auth.password.change'))
            ->post(route('auth.password.change.store'), [
                'current_password' => 'temporary-123',
                'password' => 'temporary-123',
                'password_confirmation' => 'temporary-123',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue((bool) $staff->fresh()->must_change_password);
    }

    public function test_an_account_without_the_flag_signs_straight_in(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);

        $this->post('/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_an_administrator_password_reset_reinstates_the_requirement(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$staff->id}/password", [
                'password' => 'set-by-admin-123',
                'password_confirmation' => 'set-by-admin-123',
            ]);

        $this->assertTrue((bool) $staff->fresh()->must_change_password);
    }

    public function test_a_client_cannot_reach_the_change_screen(): void
    {
        $client = User::factory()->client()->create();

        $this->actingAs($client)
            ->get(route('auth.password.change'))
            ->assertForbidden();
    }

    public function test_editing_an_account_without_touching_the_password_does_not_force_a_change(): void
    {
        $staff = User::factory()->staff()->create([
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/staff/{$staff->id}", [
                'name' => 'Rosa M. Mendoza',
                'email' => $staff->email,
                'role' => 'staff',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.staff.index'));

        $this->assertFalse((bool) $staff->fresh()->must_change_password);
    }
}