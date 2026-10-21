<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refused priority claim.
 *
 * The reason was kept in the audit log and in a status history note, which
 * is where a decision belongs - but the client was left with a claim that had
 * simply vanished from their page, and a reason they had to find by reading
 * an activity list. The claim itself was not erased either: what the office
 * refused is the grant, not the fact that the client made the claim, and a
 * client who comes back with the right document has to be able to have it
 * verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('priority_refused_at')->nullable()->after('priority_verified_by');
            $table->string('priority_refused_reason', 500)->nullable()->after('priority_refused_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['priority_refused_at', 'priority_refused_reason']);
        });
    }
};
