<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('class_staff')) {
            return;
        }

        // Clean duplicates: keep the most recent link per staff
        $dups = DB::table('class_staff')
            ->select('staff_id', DB::raw('count(*) as c'))
            ->groupBy('staff_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($dups as $dup) {
            $keepId = DB::table('class_staff')
                ->where('staff_id', $dup->staff_id)
                ->orderByDesc('id')
                ->value('id');

            DB::table('class_staff')
                ->where('staff_id', $dup->staff_id)
                ->where('id', '<>', $keepId)
                ->delete();
        }

        Schema::table('class_staff', function (Blueprint $table) {
            $table->unique('staff_id', 'class_staff_staff_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('class_staff')) {
            Schema::table('class_staff', function (Blueprint $table) {
                try {
                    $table->dropUnique('class_staff_staff_unique');
                } catch (\Throwable $e) {
                }
            });
        }
    }
};
