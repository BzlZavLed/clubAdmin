<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_classes', function (Blueprint $table) {
            $table->foreignId('union_class_catalog_id')
                ->nullable()
                ->after('club_id')
                ->constrained('union_class_catalogs')
                ->nullOnDelete();

            $table->unique(['club_id', 'union_class_catalog_id'], 'club_classes_club_union_class_unique');
        });

        DB::statement("
            UPDATE club_classes AS cc
            SET union_class_catalog_id = ucc.id
            FROM clubs AS c
            JOIN districts AS d ON d.id = c.district_id
            JOIN associations AS a ON a.id = d.association_id
            JOIN union_club_catalogs AS ucl
              ON ucl.union_id = a.union_id
            JOIN union_class_catalogs AS ucc
              ON ucc.union_club_catalog_id = ucl.id
            WHERE cc.club_id = c.id
              AND c.evaluation_system = 'carpetas'
              AND cc.union_class_catalog_id IS NULL
              AND LOWER(TRIM(ucl.name)) = LOWER(TRIM(c.club_type))
              AND LOWER(TRIM(ucc.name)) = LOWER(TRIM(cc.class_name))
        ");
    }

    public function down(): void
    {
        Schema::table('club_classes', function (Blueprint $table) {
            $table->dropUnique('club_classes_club_union_class_unique');
            $table->dropConstrainedForeignId('union_class_catalog_id');
        });
    }
};
