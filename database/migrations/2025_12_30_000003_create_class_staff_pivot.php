<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop unique constraint from previous one-to-one setup if present
        if (Schema::hasTable('club_classes') && Schema::hasColumn('club_classes', 'assigned_staff_id')) {
            try {
                DB::statement('ALTER TABLE club_classes DROP CONSTRAINT IF EXISTS club_classes_assigned_staff_unique');
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                DB::statement('ALTER TABLE club_classes DROP INDEX club_classes_assigned_staff_unique');
            } catch (\Throwable $e) {
                // ignore (MySQL)
            }
        }

        if (!Schema::hasTable('class_staff')) {
            Schema::create('class_staff', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('club_class_id');
                $table->unsignedBigInteger('staff_id');
                $table->string('role')->nullable(); // optional: lead/support
                $table->timestamps();

                $table->foreign('club_class_id')->references('id')->on('club_classes')->cascadeOnDelete();
                $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
                $table->unique(['club_class_id', 'staff_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('class_staff')) {
            Schema::dropIfExists('class_staff');
        }
    }
};
