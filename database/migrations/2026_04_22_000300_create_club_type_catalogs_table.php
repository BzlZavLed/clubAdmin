<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_type_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        $now = now();
        DB::table('club_type_catalogs')->insert([
            [
                'code' => 'adventurers',
                'name' => 'Aventureros',
                'sort_order' => 1,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'pathfinders',
                'name' => 'Conquistadores',
                'sort_order' => 2,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'master_guide',
                'name' => 'Guias Mayores',
                'sort_order' => 3,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('union_club_catalogs', function (Blueprint $table) {
            $table->string('club_type')->nullable()->after('name');
            $table->index(['union_id', 'club_type'], 'union_club_catalogs_union_type_idx');
        });

        DB::table('union_club_catalogs')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($catalog): void {
                $normalized = mb_strtolower(trim((string) $catalog->name));
                $normalized = str_replace(['-', '_'], ' ', $normalized);
                $normalized = preg_replace('/\s+/', ' ', $normalized);

                $clubType = match ($normalized) {
                    'adventurers', 'adventurer', 'aventureros', 'aventurero' => 'adventurers',
                    'pathfinders', 'pathfinder', 'conquistadores', 'conquistador' => 'pathfinders',
                    'master guide', 'master guides', 'guia mayor', 'guias mayores', 'guías mayores', 'guia mayores' => 'master_guide',
                    default => null,
                };

                if ($clubType) {
                    DB::table('union_club_catalogs')
                        ->where('id', $catalog->id)
                        ->update(['club_type' => $clubType]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('union_club_catalogs', function (Blueprint $table) {
            $table->dropIndex('union_club_catalogs_union_type_idx');
            $table->dropColumn('club_type');
        });

        Schema::dropIfExists('club_type_catalogs');
    }
};
