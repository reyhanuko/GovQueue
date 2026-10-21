<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\PriorityDocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The soft copy of the document a priority claim is made on.
 *
 * A reference number says that a document exists. It does not let anybody
 * check that the document is the one the claim says it is, and it leaves a
 * client who cannot get to the office before their appointment - or who is
 * serving somebody who cannot - with no way to show their evidence at all.
 *
 * The file is a client's identity document, so the tests here are as much
 * about who may see it as about whether it arrives.
 */
class PriorityDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PriorityDocumentStore::DISK);

        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    private function slot(): TimeSlot
    {
        $start = now()->addDay()->setTime(9, 0);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function document(string $name = 'pwd-id.jpg', string $contents = 'a photograph'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /* ------------------------------------------------------------------ */
    /* The client sends one */
    /* ------------------------------------------------------------------ */

    public function test_a_client_can_send_a_soft_copy_with_their_claim(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
                'priority_reference_pwd' => 'PWD-12345',
                'priority_document' => $this->document(),
            ])
            ->assertRedirect();

        $appointment = Appointment::sole();

        $this->assertNotNull(
            $appointment->priority_document_path,
            'The document was not kept with the claim.',
        );
        $this->assertSame('pwd-id.jpg', $appointment->priority_document_name);
        Storage::disk(PriorityDocumentStore::DISK)->assertExists($appointment->priority_document_path);
    }

    public function test_the_document_is_optional(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
                'priority_reference_pwd' => 'PWD-12345',
            ])
            ->assertRedirect();

        $this->assertNull(Appointment::sole()->priority_document_path);
    }

    public function test_a_file_that_is_not_a_document_is_refused(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
                'priority_reference_pwd' => 'PWD-12345',
                'priority_document' => $this->document('payload.exe', 'MZ'),
            ])
            ->assertSessionHasErrors('priority_document');

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_document_that_is_too_large_is_refused(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
                'priority_reference_pwd' => 'PWD-12345',
                'priority_document' => UploadedFile::fake()->create('big.jpg', PriorityDocumentStore::MAX_KILOBYTES + 1),
            ])
            ->assertSessionHasErrors('priority_document');

        $this->assertSame(0, Appointment::count());
    }

    /**
     * A document nobody claimed is a client's identity document sitting on a
     * disk with nothing pointing at it.
     */
    public function test_a_document_is_removed_when_the_booking_does_not_happen(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => 99999,
                'claims_pwd' => '1',
                'priority_reference_pwd' => 'PWD-12345',
                'priority_document' => $this->document(),
            ]);

        $this->assertSame(
            0,
            count(Storage::disk(PriorityDocumentStore::DISK)->allFiles()),
            'A document was left behind by a booking that never happened.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Who may see it */
    /* ------------------------------------------------------------------ */

    private function appointmentWithDocument(?User $client = null): Appointment
    {
        $appointment = Appointment::factory()->create([
            'user_id' => ($client ?? $this->client)->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $this->slot()->id,
            'status' => 'confirmed',
            'claims_pwd' => true,
            'priority_reference' => 'PWD-12345',
        ]);

        $appointment->forceFill([
            'priority_document_path' => 'priority-documents/2026-10/document.pdf',
            'priority_document_name' => 'pwd-id.pdf',
        ])->save();

        Storage::disk(PriorityDocumentStore::DISK)->put('priority-documents/2026-10/document.pdf', 'the document');

        return $appointment;
    }

    public function test_staff_at_the_window_can_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();

        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->actingAs($staff)
            ->get(route('staff.priority-document.show', $appointment))
            ->assertOk()
            ->assertSee('the document', false);
    }

    public function test_a_client_cannot_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();

        $this->actingAs($this->client)
            ->get(route('staff.priority-document.show', $appointment))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();

        $this->get(route('staff.priority-document.show', $appointment))
            ->assertRedirect(route('login'));
    }

    public function test_staff_at_another_window_cannot_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();

        $otherWindow = ServiceWindow::factory()->create(['code' => 'WINDOW_2']);
        $staff = User::factory()->staff()->create(['service_window_id' => $otherWindow->id]);

        $this->actingAs($staff)
            ->get(route('staff.priority-document.show', $appointment))
            ->assertForbidden();
    }

    public function test_an_administrator_can_open_any_windows_document(): void
    {
        $appointment = $this->appointmentWithDocument();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('staff.priority-document.show', $appointment))
            ->assertOk();
    }

    public function test_a_ticket_with_no_document_is_not_found(): void
    {
        $appointment = Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $this->slot()->id,
        ]);

        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->actingAs($staff)
            ->get(route('staff.priority-document.show', $appointment))
            ->assertNotFound();
    }
}
