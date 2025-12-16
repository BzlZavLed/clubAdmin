<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('club_classes') || !Schema::hasColumn('club_classes', 'assigned_staff_id')) {
            return;
        }

        // Clean duplicates: keep the lowest id per staff, null the rest.
        $dups = DB::table('club_classes')
            ->select('assigned_staff_id', DB::raw('count(*) as c'))
            ->whereNotNull('assigned_staff_id')
            ->groupBy('assigned_staff_id')
            ->having('c', '>', 1)
            ->get();

        foreach ($dups as $dup) {
            $keepId = DB::table('club_classes')
                ->where('assigned_staff_id', $dup->assigned_staff_id)
                ->orderBy('id')
                ->value('id');

            DB::table('club_classes')
                ->where('assigned_staff_id', $dup->assigned_staff_id)
                ->where('id', '<>', $keepId)
                ->update(['assigned_staff_id' => null]);
        }

        Schema::table('club_classes', function (Blueprint $table) {
            $table->unique('assigned_staff_id', 'club_classes_assigned_staff_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('club_classes')) {
            Schema::table('club_classes', function (Blueprint $table) {
                try {
                    $table->dropUnique('club_classes_assigned_staff_unique');
                } catch (\Throwable $e) {
                    // ignore if missing
                }
            });
        }
    }
};
