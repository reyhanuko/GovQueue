<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\PriorityDocument;
use App\Services\PriorityDocumentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The soft copy of the document a priority claim is made on.
 *
 * Served to staff, and to staff only. The file is a client's identity
 * document, so it is not on a public URL and it is not served by the
 * framework's own file route: the only way to see it is to be a member of
 * staff working the window the client booked at.
 *
 * Administrators may open any window's document, because they administer
 * every window; a staffer may not open a window they do not work, and the
 * console refuses them the ticket itself.
 *
 * The document is addressed by its own id rather than by the appointment's,
 * because a client may send more than one - a PWD ID and a medical
 * certificate - and the clerk has to be able to open each of them.
 */
class PriorityDocumentController extends Controller
{
    public function show(Request $request, PriorityDocument $document): Response
    {
        $appointment = $document->appointment;

        abort_unless($appointment, 404);

        $staff = $request->user();

        abort_if(
            ! $staff->isAdmin()
            && (int) $appointment->service_window_id !== (int) $staff->service_window_id,
            403,
            'That ticket belongs to a different service window.',
        );

        $disk = Storage::disk(PriorityDocumentStore::DISK);

        abort_unless($disk->exists($document->path), 404);

        /*
         * Shown in the browser, not downloaded: the clerk is checking a
         * document against a claim, and a download they have to open in
         * another program is a document they may not check at all.
         *
         * The name the client gave the file is put in the header, so it has
         * to be stripped of anything that could end the header early - the
         * name is the client's own, and it is not a filename this system
         * chose.
         */
        $name = preg_replace('/[^\w.\- ]+/u', '_', $document->displayName());

        return response($disk->get($document->path), 200, [
            'Content-Type' => $this->contentTypeFor($document->path),
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    /**
     * The content type of a stored document.
     *
     * From the stored path, not from the name the client gave it: the name
     * is theirs, and the path is ours.
     */
    private function contentTypeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
