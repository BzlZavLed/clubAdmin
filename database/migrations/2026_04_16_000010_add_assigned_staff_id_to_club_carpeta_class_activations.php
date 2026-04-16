<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('club_carpeta_class_activations', 'assigned_staff_id')) {
            Schema::table('club_carpeta_class_activations', function (Blueprint $table) {
                $table->foreignId('assigned_staff_id')
                    ->nullable()
                    ->after('union_class_catalog_id')
                    ->constrained('staff')
                    ->nullOnDelete();
            });
        }

        // Backfill from existing staff assignments
        DB::statement("
            UPDATE club_carpeta_class_activations cca
            SET assigned_staff_id = (
                SELECT s.id
                FROM staff s
                WHERE s.assigned_carpeta_class_activation_id = cca.id
                LIMIT 1
            )
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('club_carpeta_class_activations', 'assigned_staff_id')) {
            Schema::table('club_carpeta_class_activations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_staff_id');
            });
        }
    }
};
