<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_windows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('avg_service_minutes')->default(10);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_window_id')->constrained('service_windows')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->decimal('fee', 10, 2)->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(10);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Added here rather than in the users migration because service_windows
        // must already exist before users can reference it.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('service_window_id')->nullable()->after('last_login_at')
                ->constrained('service_windows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['service_window_id']);
            $table->dropColumn('service_window_id');
        });

        Schema::dropIfExists('services');
        Schema::dropIfExists('service_windows');
    }
};
