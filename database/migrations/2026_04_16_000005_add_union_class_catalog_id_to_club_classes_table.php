<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('club_classes', 'union_class_catalog_id')) {
            Schema::table('club_classes', function (Blueprint $table) {
                $table->foreignId('union_class_catalog_id')
                    ->nullable()
                    ->after('club_id')
                    ->constrained('union_class_catalogs')
                    ->nullOnDelete();
            });
        }

        // Add unique index if not already present
        $indexes = collect(DB::select("SHOW INDEX FROM club_classes WHERE Key_name = 'club_classes_club_union_class_unique'"));
        if ($indexes->isEmpty()) {
            Schema::table('club_classes', function (Blueprint $table) {
                $table->unique(['club_id', 'union_class_catalog_id'], 'club_classes_club_union_class_unique');
            });
        }

        DB::statement("
            UPDATE club_classes AS cc
            JOIN clubs AS c ON cc.club_id = c.id
            JOIN districts AS d ON d.id = c.district_id
            JOIN associations AS a ON a.id = d.association_id
            JOIN union_club_catalogs AS ucl ON ucl.union_id = a.union_id
            JOIN union_class_catalogs AS ucc ON ucc.union_club_catalog_id = ucl.id
            SET cc.union_class_catalog_id = ucc.id
            WHERE c.evaluation_system = 'carpetas'
              AND cc.union_class_catalog_id IS NULL
              AND LOWER(TRIM(ucl.name)) = LOWER(TRIM(c.club_type))
              AND LOWER(TRIM(ucc.name)) = LOWER(TRIM(cc.class_name))
        ");
    }

    public function down(): void
    {
        $indexes = collect(DB::select("SHOW INDEX FROM club_classes WHERE Key_name = 'club_classes_club_union_class_unique'"));
        if ($indexes->isNotEmpty()) {
            Schema::table('club_classes', function (Blueprint $table) {
                $table->dropUnique('club_classes_club_union_class_unique');
            });
        }

        if (Schema::hasColumn('club_classes', 'union_class_catalog_id')) {
            Schema::table('club_classes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('union_class_catalog_id');
            });
        }
    }
};
