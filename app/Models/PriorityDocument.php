<?php

namespace App\Models;

use App\Enums\PriorityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A soft copy of the document a priority claim is made on.
 *
 * One row per document, tied to the claim it supports. A client may claim
 * more than one category, and each category is made on a different document
 * - a PWD ID and a medical certificate - so a ticket that carries one file
 * for two claims is a file the clerk cannot match to a claim.
 */
class PriorityDocument extends Model
{
    protected $fillable = [
        'appointment_id',
        'claim_type',
        'path',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'claim_type' => PriorityType::class,
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * The claim this document supports, if it was sent for one.
     */
    public function claim(): ?PriorityType
    {
        return $this->claim_type;
    }

    /**
     * What to call this document on the screen.
     *
     * "PWD document" when it was sent for a PWD claim, and plain "document"
     * for one sent before the claim was recorded - which is honest, because
     * that is all we know about it.
     */
    public function label(): string
    {
        return $this->claim_type
            ? $this->claim_type->label().' document'
            : 'Document';
    }

    /**
     * The name the client gave the file, or something usable.
     */
    public function displayName(): string
    {
        return $this->name ?: 'Attached document';
    }
}
