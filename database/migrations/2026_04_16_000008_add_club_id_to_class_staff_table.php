<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_staff', function (Blueprint $table) {
            $table->foreignId('club_id')
                ->nullable()
                ->after('id')
                ->constrained('clubs')
                ->nullOnDelete();
        });

        // Backfill club_id from the related club_class
        DB::statement("
            UPDATE class_staff cs
            SET club_id = (
                SELECT cc.club_id FROM club_classes cc WHERE cc.id = cs.club_class_id
            )
            WHERE cs.club_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('class_staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_id');
        });
    }
};
