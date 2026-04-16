<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('assigned_carpeta_class_activation_id')
                ->nullable()
                ->after('assigned_class')
                ->constrained('club_carpeta_class_activations')
                ->nullOnDelete();
        });

        // Migrate existing data: map union_class_catalog_id + club_id → activation id
        DB::statement("
            UPDATE staff s
            SET assigned_carpeta_class_activation_id = (
                SELECT cca.id
                FROM club_carpeta_class_activations cca
                WHERE cca.union_class_catalog_id = s.assigned_union_class_catalog_id
                  AND cca.club_id = s.club_id
                LIMIT 1
            )
            WHERE s.assigned_union_class_catalog_id IS NOT NULL
        ");

        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_union_class_catalog_id');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('assigned_union_class_catalog_id')
                ->nullable()
                ->after('assigned_class')
                ->constrained('union_class_catalogs')
                ->nullOnDelete();
        });

        DB::statement("
            UPDATE staff s
            SET assigned_union_class_catalog_id = (
                SELECT cca.union_class_catalog_id
                FROM club_carpeta_class_activations cca
                WHERE cca.id = s.assigned_carpeta_class_activation_id
                LIMIT 1
            )
            WHERE s.assigned_carpeta_class_activation_id IS NOT NULL
        ");

        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_carpeta_class_activation_id');
        });
    }
};
