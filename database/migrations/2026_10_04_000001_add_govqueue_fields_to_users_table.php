<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['client', 'staff', 'admin'])->default('client')->after('email')->index();
            $table->string('phone', 20)->nullable()->after('role');
            $table->string('address')->nullable()->after('phone');
            $table->string('barangay')->nullable()->after('address');
            $table->date('birthdate')->nullable()->after('barangay');
            $table->boolean('is_active')->default(true)->after('birthdate');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['service_window_id']);
            $table->dropColumn([
                'role', 'phone', 'address', 'barangay', 'birthdate',
                'is_active', 'last_login_at',
            ]);
        });
    }
};
