<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index for age-based pruning of the audit trail.
 *
 * Every write path records an entry - a booking, a cancellation, a refused
 * priority claim, a refused admin action - and nothing ever removed one, so the
 * table grows without bound and the admin audit screen has to scan all of it to
 * show a filtered page.
 *
 * The existing indexes cover `action` and `subject`, neither of which helps a
 * "delete everything older than N days" statement, so the prune would be a full
 * table scan. On a table that only grows, that is the worst possible shape: the
 * longer it runs, the slower the maintenance job that is supposed to keep it
 * manageable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // Matching the filter the admin audit screen already offers.
            $table->index(['action', 'created_at'], 'audit_logs_action_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_created_index');
        });
    }
};
