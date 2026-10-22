<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Priority columns that must not exist on the user record.
     *
     * The account says who somebody is. It does not say what they may claim
     * on a transaction:
     *
     *  - `claims_senior_citizen` was a stored copy of the RA 9994 entitlement,
     *    which is derived from the date of birth. A stored copy is a copy that
     *    can be wrong, and it was wrong: it was set from a tick on the
     *    registration form and had to be reconciled afterwards.
     *  - `is_pwd`, `is_pregnant` and the reference fields belong to a
     *    transaction. PWD (RA 10754) and pregnancy are claimed per appointment
     *    against a document, verified by staff at the counter, so an
     *    account-level copy was never true and went stale immediately.
     *
     * Every one of them is guarded by a hasColumn() check, because the point of
     * this migration is to remove what is there, not to insist on what is not.
     */
    private const ACCOUNT_LEVEL_PRIORITY_COLUMNS = [
        'claims_senior_citizen',
        'is_pwd',
        'is_pregnant',
        'pwd_reference',
        'pregnancy_reference',
        'priority_reference',
    ];

    public function up(): void
    {
        // One column per statement: SQLite cannot drop two columns in one
        // ALTER TABLE, and the same code then runs on either engine.
        foreach (self::ACCOUNT_LEVEL_PRIORITY_COLUMNS as $column) {
            if (! Schema::hasColumn('users', $column)) {
                continue;
            }

            Schema::table('users', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        /*
         * The senior-citizen column is restored, because the migration that
         * introduced it is still in the history and a rollback has to leave
         * the schema that the earlier migrations describe.
         *
         * The per-transaction columns are not restored: they were never part
         * of a working design, and putting them back would re-create the
         * account-level claims this migration exists to remove.
         */
        if (! Schema::hasColumn('users', 'claims_senior_citizen')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('claims_senior_citizen')->default(false)->after('birthdate')->index();
            });
        }
    }
};
