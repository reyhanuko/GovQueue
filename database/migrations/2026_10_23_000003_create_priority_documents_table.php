<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One document per claim, not one per appointment.
 *
 * A priority claim is made on a document, and a client may make more than
 * one claim: a PWD entitlement and a pregnancy claim are two claims on two
 * different documents - a PWD ID and a medical certificate. The old form had
 * one file input for the whole ticket, so a client claiming both could send
 * one document and not the other, and the clerk was left with a file and no
 * way to know which claim it was meant to support.
 *
 * That is the same conflation as calling an unverified claim "Regular": one
 * field where there are two facts.
 *
 * So each document is a row, tied to the claim it supports. The claim_type
 * is nullable because a document sent before this change belonged to
 * whichever claim it belonged to, and the old form did not say which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('priority_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('appointment_id')
                ->constrained()
                ->cascadeOnDelete();

            // The claim this document supports: pwd, pregnant, or null for a
            // document sent before the claim was recorded.
            $table->string('claim_type', 40)->nullable();

            $table->string('path', 255);

            // The name the client gave the file, kept so staff can recognise
            // it ("PWD-ID-scan.jpg") without opening it.
            $table->string('name', 255)->nullable();

            $table->timestamps();

            $table->index(['appointment_id', 'claim_type']);
        });

        // Carry the one document the old form allowed over to the new table.
        DB::table('appointments')
            ->whereNotNull('priority_document_path')
            ->orderBy('id')
            ->each(function ($appointment) {
                DB::table('priority_documents')->insert([
                    'appointment_id' => $appointment->id,
                    'claim_type' => null,
                    'path' => $appointment->priority_document_path,
                    'name' => $appointment->priority_document_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['priority_document_path', 'priority_document_name']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('priority_document_path', 255)->nullable()->after('priority_reference');
            $table->string('priority_document_name', 255)->nullable()->after('priority_document_path');
        });

        DB::table('priority_documents')
            ->whereNull('claim_type')
            ->orderBy('id')
            ->each(function ($document) {
                DB::table('appointments')
                    ->where('id', $document->appointment_id)
                    ->update([
                        'priority_document_path' => $document->path,
                        'priority_document_name' => $document->name,
                    ]);
            });

        Schema::dropIfExists('priority_documents');
    }
};
