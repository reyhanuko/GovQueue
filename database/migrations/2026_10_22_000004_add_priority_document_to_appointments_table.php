<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A soft copy of the document a priority claim is made on.
 *
 * A reference number says that a document exists; it does not let anybody
 * check that the document is the one the claim says it is. A client who cannot
 * get to the office before their appointment, or who is serving someone who
 * cannot, has no way to show their evidence at all - and a statutory
 * category they qualify for goes unused.
 *
 * The file is kept on the private disk and is never served from a public
 * URL: it is a client's identity document, and it is shown to the staff who
 * have to verify the claim and to nobody else.
 *
 * The reference stays. The document is the evidence; the reference is what
 * the office writes on the ticket, and both are useful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('priority_document_path', 255)->nullable()->after('priority_reference');
            // The name the client gave the file, kept so staff can recognise
            // it ("PWD-ID-scan.jpg") without opening it.
            $table->string('priority_document_name', 255)->nullable()->after('priority_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['priority_document_path', 'priority_document_name']);
        });
    }
};
