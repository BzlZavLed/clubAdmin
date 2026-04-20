<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('club_carpeta_class_activations')) {
            Schema::create('club_carpeta_class_activations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
                $table->foreignId('union_class_catalog_id')->constrained('union_class_catalogs')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['club_id', 'union_class_catalog_id'], 'club_carpeta_class_activations_unique');
            });
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::table('club_classes')
                ->whereNotNull('union_class_catalog_id')
                ->distinct()
                ->get(['club_id', 'union_class_catalog_id'])
                ->each(function ($row) {
                    DB::table('club_carpeta_class_activations')->insertOrIgnore([
                        'club_id' => $row->club_id,
                        'union_class_catalog_id' => $row->union_class_catalog_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        } elseif ($driver === 'pgsql') {
            DB::statement("
                INSERT INTO club_carpeta_class_activations (club_id, union_class_catalog_id, created_at, updated_at)
                SELECT DISTINCT club_id, union_class_catalog_id, NOW(), NOW()
                FROM club_classes
                WHERE union_class_catalog_id IS NOT NULL
                ON CONFLICT (club_id, union_class_catalog_id) DO NOTHING
            ");
        } else {
            DB::statement("
                INSERT IGNORE INTO club_carpeta_class_activations (club_id, union_class_catalog_id, created_at, updated_at)
                SELECT DISTINCT club_id, union_class_catalog_id, NOW(), NOW()
                FROM club_classes
                WHERE union_class_catalog_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('club_carpeta_class_activations');
    }
};
