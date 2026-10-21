<?php

namespace App\Services;

use App\Enums\PriorityType;
use App\Exceptions\SlotUnavailableException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The soft copy of the document a priority claim is made on.
 *
 * A priority claim is made on a document: a PWD ID, a medical certificate, a
 * referral letter. Until now the client could only give a reference number for
 * it, which says that a document exists without letting anybody check that
 * the document is the one the claim says it is - and which leaves a client who
 * cannot get to the office before their appointment, or who is serving
 * someone who cannot, with no way to show their evidence at all.
 *
 * The file is kept on the private disk. It is never on a public URL: it is a
 * client's identity document, and it is shown to the staff who have to verify
 * the claim and to nobody else.
 */
class PriorityDocumentStore
{
    /** The disk the documents live on. Private, and never served directly. */
    public const DISK = 'local';

    /** Where under the disk they live. */
    public const DIRECTORY = 'priority-documents';

    /**
     * The file types a document may be.
     *
     * A photograph of an ID, a scan of a certificate, a PDF. Nothing that
     * could carry a script, and nothing that has to be rendered by a browser
     * to be seen.
     */
    public const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png'];

    /** The largest document that will be accepted. */
    public const MAX_KILOBYTES = 5 * 1024;

    /**
     * Store an uploaded document and return the details to record.
     *
     * The claim is passed in and recorded with the file, because a client
     * may claim more than one category and each has its own document. A file
     * with no claim attached is a file the clerk cannot match to anything.
     *
     * @return array{path: string, name: string, size: int, claim: PriorityType}
     */
    public function store(UploadedFile $file, PriorityType $claim): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if (! in_array($extension, self::ALLOWED, true)) {
            throw SlotUnavailableException::unsupportedDocument();
        }

        // A megabyte is a megabyte whatever the client uploaded, so the size
        // is measured rather than believed.
        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw SlotUnavailableException::documentTooLarge(self::MAX_KILOBYTES);
        }

        $name = Str::uuid()->toString().'.'.$extension;

        $path = self::DIRECTORY.'/'.now()->format('Y-m').'/'.$name;

        // storeAs() rather than a read-and-write: the file is streamed to the
        // disk instead of being held in memory, and the name is ours - the
        // client's own filename is kept for display only.
        $stored = $file->storeAs(
            self::DIRECTORY.'/'.now()->format('Y-m'),
            $name,
            ['disk' => self::DISK],
        );

        if ($stored === false) {
            throw SlotUnavailableException::documentNotStored();
        }

        return [
            'path' => $stored,
            'name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'size' => (int) $file->getSize(),
            'claim' => $claim,
        ];
    }

    /**
     * Remove a stored document.
     *
     * Used when the booking it belongs to does not happen: the file is on the
     * disk, and the row that would have said whose it was does not exist.
     */
    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Does a document exist for this appointment?
     */
    public function exists(?string $path): bool
    {
        return filled($path) && Storage::disk(self::DISK)->exists($path);
    }
}
