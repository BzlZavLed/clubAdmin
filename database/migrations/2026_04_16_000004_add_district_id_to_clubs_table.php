<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('clubs', 'district_id')) {
            Schema::table('clubs', function (Blueprint $table) {
                $table->foreignId('district_id')
                    ->nullable()
                    ->after('church_id')
                    ->constrained('districts')
                    ->nullOnDelete();
            });
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::table('clubs')
                ->whereNull('district_id')
                ->whereNotNull('church_id')
                ->get(['id', 'church_id'])
                ->each(function ($club) {
                    $districtId = DB::table('churches')
                        ->where('id', $club->church_id)
                        ->value('district_id');

                    if ($districtId) {
                        DB::table('clubs')
                            ->where('id', $club->id)
                            ->update(['district_id' => $districtId]);
                    }
                });
        } elseif ($driver === 'pgsql') {
            DB::statement('
                UPDATE clubs
                SET district_id = churches.district_id
                FROM churches
                WHERE clubs.church_id = churches.id
                  AND clubs.district_id IS NULL
            ');
        } else {
            DB::statement('
                UPDATE clubs
                JOIN churches ON clubs.church_id = churches.id
                SET clubs.district_id = churches.district_id
                WHERE clubs.district_id IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clubs', 'district_id')) {
            Schema::table('clubs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('district_id');
            });
        }
    }
};
