<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('class_staff', 'club_id')) {
            Schema::table('class_staff', function (Blueprint $table) {
                $table->foreignId('club_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('clubs')
                    ->nullOnDelete();
            });
        }

        // Backfill club_id from the related club_class
        if (DB::getDriverName() === 'sqlite') {
            DB::table('class_staff')
                ->whereNull('club_id')
                ->get(['id', 'club_class_id'])
                ->each(function ($row) {
                    $clubId = DB::table('club_classes')
                        ->where('id', $row->club_class_id)
                        ->value('club_id');

                    if ($clubId) {
                        DB::table('class_staff')
                            ->where('id', $row->id)
                            ->update(['club_id' => $clubId]);
                    }
                });
        } else {
            DB::statement("
                UPDATE class_staff cs
                SET club_id = (
                    SELECT cc.club_id FROM club_classes cc WHERE cc.id = cs.club_class_id
                )
                WHERE cs.club_id IS NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('class_staff', 'club_id')) {
            Schema::table('class_staff', function (Blueprint $table) {
                $table->dropConstrainedForeignId('club_id');
            });
        }
    }
};
