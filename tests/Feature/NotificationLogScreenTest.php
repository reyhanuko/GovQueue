<?php

namespace Tests\Feature;

use App\Enums\NotificationChannelType;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationLogScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function log(array $overrides = []): NotificationLog
    {
        return NotificationLog::create(array_merge([
            'appointment_id' => null,
            'user_id' => User::factory()->client()->create()->id,
            'channel' => NotificationChannelType::Email,
            'type' => 'appointment.confirmed',
            'destination' => 'ana@example.test',
            'status' => NotificationStatus::Queued,
            'attempts' => 1,
        ], $overrides));
    }

    public function test_the_screen_is_admin_only(): void
    {
        $this->get('/admin/notifications')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->client()->create())
            ->get('/admin/notifications')
            ->assertForbidden();

        $this->actingAs(User::factory()->staff()->create())
            ->get('/admin/notifications')
            ->assertForbidden();
    }

    public function test_an_admin_can_open_the_screen(): void
    {
        $this->log();

        $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee('Client delivery log')
            ->assertSee('ana@example.test');
    }

    /**
     * The screen used to carry three different names - "Notification log" in the
     * sidebar, "Client notifications" as the heading, "Notifications" as the tab
     * title - and all three used the same word as the staff inbox, which is a
     * different thing entirely.
     */
    public function test_the_three_names_agree(): void
    {
        $this->log();

        $content = $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Client delivery log', $content);
        $this->assertStringNotContainsString('Client notifications', $content);
    }

    public function test_an_empty_log_explains_itself(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee('No notifications have been attempted yet.');
    }

    public function test_it_counts_by_status(): void
    {
        $this->log(['status' => NotificationStatus::Sent]);
        $this->log(['status' => NotificationStatus::Failed]);
        $this->log(['status' => NotificationStatus::Failed]);

        $response = $this->actingAs($this->admin())->get('/admin/notifications');

        $counts = $response->viewData('counts');

        $this->assertSame(1, $counts['sent']);
        $this->assertSame(2, $counts['failed']);
    }

    public function test_it_can_filter_by_status(): void
    {
        $this->log(['status' => NotificationStatus::Sent, 'destination' => 'sent@example.test']);
        $this->log(['status' => NotificationStatus::Failed, 'destination' => 'failed@example.test']);

        $this->actingAs($this->admin())
            ->get('/admin/notifications?status=failed')
            ->assertOk()
            ->assertSee('failed@example.test')
            ->assertDontSee('sent@example.test');
    }

    public function test_it_can_filter_by_channel(): void
    {
        $this->log(['channel' => NotificationChannelType::Sms, 'destination' => '09171234567']);
        $this->log(['channel' => NotificationChannelType::Email, 'destination' => 'ana@example.test']);

        $this->actingAs($this->admin())
            ->get('/admin/notifications?channel=sms')
            ->assertOk()
            ->assertSee('09171234567')
            ->assertDontSee('ana@example.test');
    }

    public function test_it_shows_the_failure_reason(): void
    {
        $this->log([
            'status' => NotificationStatus::Failed,
            'error_message' => 'The semaphore gateway is not configured.',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee('The semaphore gateway is not configured.');
    }

    public function test_it_disclaims_guaranteed_delivery(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee('does not guarantee message delivery');
    }

    public function test_it_warns_when_sms_is_enabled_without_a_key(): void
    {
        config([
            'govqueue.sms.driver' => 'semaphore',
            'govqueue.sms.semaphore.api_key' => null,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee('has no API key')
            ->assertSee('Delivery is not guaranteed');
    }

    public function test_it_shows_the_active_gateway(): void
    {
        config(['govqueue.sms.driver' => 'log']);

        $this->actingAs($this->admin())
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee('log');
    }
}
