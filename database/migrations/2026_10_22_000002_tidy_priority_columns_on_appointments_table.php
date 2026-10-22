<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that were not doing their job.
 *
 * priority_reference holds every reference on the ticket, and a client may
 * make two claims - a PWD ID and a medical certificate - so it can hold two
 * of them. Each is validated at 120 characters, and the joined line is
 * longer than either, so a client who gave two long references was refused
 * by the column: MySQL is in strict mode, and "Data too long for column
 * 'priority_reference'" is what a client booking a certificate of indigency
 * was shown. The column is widened rather than the validation tightened,
 * because the references are the client's and the limit was never meant to
 * be a rule about how long a reference may be.
 *
 * staff_note was never written by anything and never read by anything. It
 * sat in the model's fillable list, which is enough to make the next person
 * believe the counter can leave a note on a ticket. It cannot: the notes the
 * counter writes live on the status history, where they are recorded with
 * the staff member who wrote them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // nullable() has to be said out loud: change() rebuilds the column
            // from what it is given, and a string() without it would make the
            // column NOT NULL and rewrite every empty reference as an empty
            // string rather than as nothing.
            $table->string('priority_reference', 255)->nullable()->change();
            $table->dropColumn('staff_note');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->text('staff_note')->nullable();
            $table->string('priority_reference', 120)->nullable()->change();
        });
    }
};
