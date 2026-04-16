<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->foreignId('district_id')
                ->nullable()
                ->after('church_id')
                ->constrained('districts')
                ->nullOnDelete();
        });

        DB::statement('
            UPDATE clubs
            SET district_id = churches.district_id
            FROM churches
            WHERE clubs.church_id = churches.id
              AND clubs.district_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_id');
        });
    }
};
