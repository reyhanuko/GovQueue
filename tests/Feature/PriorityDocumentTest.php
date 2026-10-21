<?php

namespace Tests\Feature;

use App\Enums\PriorityType;
use App\Models\Appointment;
use App\Models\PriorityDocument;
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
 * check that the document is the one the claim says it is, and it left a
 * client who cannot get to the office before their appointment - or who is
 * serving somebody who cannot - with no way to show their evidence at all.
 *
 * One document per claim, because a client may claim more than one category
 * and each has its own document. A PWD ID and a medical certificate are not
 * the same piece of paper, and a file the clerk cannot match to a claim is a
 * file that cannot be checked.
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
                'priority_document_pwd' => $this->document(),
            ])
            ->assertRedirect();

        $appointment = Appointment::sole();

        $this->assertCount(1, $appointment->priorityDocuments);

        $document = $appointment->priorityDocuments->first();

        $this->assertSame('pwd-id.jpg', $document->name);
        $this->assertSame(PriorityType::Pwd, $document->claim_type);
        Storage::disk(PriorityDocumentStore::DISK)->assertExists($document->path);
    }

    /**
     * Two claims, two documents.
     *
     * A client claiming PWD and pregnancy at the same time is making two
     * claims on two different documents. With one file field for the whole
     * ticket, one of the two claims had no evidence at all, and the clerk
     * could not tell which document they were holding.
     */
    public function test_two_claims_can_each_bring_their_own_document(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
                'claims_pregnant' => '1',
                'priority_reference_pwd' => 'PWD-12345',
                'priority_reference_pregnant' => 'MC-2212',
                'priority_document_pwd' => $this->document('pwd-id.jpg'),
                'priority_document_pregnant' => $this->document('medical-certificate.pdf'),
            ])
            ->assertRedirect();

        $appointment = Appointment::sole();

        $this->assertCount(2, $appointment->priorityDocuments);

        $byClaim = $appointment->priorityDocuments->keyBy(fn (PriorityDocument $d) => $d->claim_type?->value);

        $this->assertSame('pwd-id.jpg', $byClaim['pwd']->name);
        $this->assertSame('medical-certificate.pdf', $byClaim['pregnant']->name);

        // Each file is kept, and each is on the disk under its own name.
        foreach ($appointment->priorityDocuments as $document) {
            Storage::disk(PriorityDocumentStore::DISK)->assertExists($document->path);
        }
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

        $this->assertCount(0, Appointment::sole()->priorityDocuments);
    }

    public function test_a_file_that_is_not_a_document_is_refused(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => $slot->id,
                'claims_pwd' => '1',
                'priority_reference_pwd' => 'PWD-12345',
                'priority_document_pwd' => $this->document('payload.exe', 'MZ'),
            ])
            ->assertSessionHasErrors('priority_document_pwd');

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
                'priority_document_pwd' => UploadedFile::fake()->create('big.jpg', PriorityDocumentStore::MAX_KILOBYTES + 1),
            ])
            ->assertSessionHasErrors('priority_document_pwd');

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
                'priority_document_pwd' => $this->document(),
            ]);

        $this->assertSame(
            0,
            count(Storage::disk(PriorityDocumentStore::DISK)->allFiles()),
            'A document was left behind by a booking that never happened.',
        );
    }

    /**
     * Two documents, and the booking fails: both have to go.
     */
    public function test_every_document_is_removed_when_the_booking_does_not_happen(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.booking.store', $this->service), [
                'time_slot_id' => 99999,
                'claims_pwd' => '1',
                'claims_pregnant' => '1',
                'priority_reference_pwd' => 'PWD-12345',
                'priority_reference_pregnant' => 'MC-2212',
                'priority_document_pwd' => $this->document('pwd-id.jpg'),
                'priority_document_pregnant' => $this->document('certificate.pdf'),
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

        $document = $appointment->priorityDocuments()->create([
            'claim_type' => PriorityType::Pwd->value,
            'path' => 'priority-documents/2026-10/document.pdf',
            'name' => 'pwd-id.pdf',
        ]);

        Storage::disk(PriorityDocumentStore::DISK)->put($document->path, 'the document');

        return $appointment;
    }

    public function test_staff_at_the_window_can_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();
        $document = $appointment->priorityDocuments->first();

        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->actingAs($staff)
            ->get(route('staff.priority-document.show', $document))
            ->assertOk()
            ->assertSee('the document', false);
    }

    public function test_a_client_cannot_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();
        $document = $appointment->priorityDocuments->first();

        $this->actingAs($this->client)
            ->get(route('staff.priority-document.show', $document))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();
        $document = $appointment->priorityDocuments->first();

        $this->get(route('staff.priority-document.show', $document))
            ->assertRedirect(route('login'));
    }

    public function test_staff_at_another_window_cannot_open_the_document(): void
    {
        $appointment = $this->appointmentWithDocument();
        $document = $appointment->priorityDocuments->first();

        $otherWindow = ServiceWindow::factory()->create(['code' => 'WINDOW_2']);
        $staff = User::factory()->staff()->create(['service_window_id' => $otherWindow->id]);

        $this->actingAs($staff)
            ->get(route('staff.priority-document.show', $document))
            ->assertForbidden();
    }

    public function test_an_administrator_can_open_any_windows_document(): void
    {
        $appointment = $this->appointmentWithDocument();
        $document = $appointment->priorityDocuments->first();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('staff.priority-document.show', $document))
            ->assertOk();
    }

    public function test_a_document_that_is_not_there_is_not_found(): void
    {
        $appointment = $this->appointmentWithDocument();
        $document = $appointment->priorityDocuments->first();

        Storage::disk(PriorityDocumentStore::DISK)->delete($document->path);

        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->actingAs($staff)
            ->get(route('staff.priority-document.show', $document))
            ->assertNotFound();
    }
}
