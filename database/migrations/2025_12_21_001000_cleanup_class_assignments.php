<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Backfill staff.assigned_class from legacy sources before dropping columns
        if (Schema::hasTable('staff') && Schema::hasTable('staff_adventurers')) {
            // From staff_adventurers.assigned_class
            $rows = DB::table('staff_adventurers')
                ->whereNotNull('assigned_class')
                ->get(['id', 'assigned_class', 'club_id']);
            foreach ($rows as $row) {
                DB::table('staff')
                    ->where('id_data', $row->id)
                    ->update([
                        'assigned_class' => $row->assigned_class,
                        'club_id' => $row->club_id ?? DB::raw('club_id'),
                    ]);
            }
        }

        if (Schema::hasTable('club_classes') && Schema::hasTable('staff')) {
            $rows = DB::table('club_classes')
                ->whereNotNull('assigned_staff_id')
                ->get(['id', 'assigned_staff_id', 'club_id']);
            foreach ($rows as $row) {
                DB::table('staff')
                    ->where('id_data', $row->assigned_staff_id)
                    ->update([
                        'assigned_class' => $row->id,
                        'club_id' => $row->club_id ?? DB::raw('club_id'),
                    ]);
            }
        }

        // Drop legacy columns
        if (Schema::hasTable('staff_adventurers') && Schema::hasColumn('staff_adventurers', 'assigned_class')) {
            Schema::table('staff_adventurers', function (Blueprint $table) {
                $table->dropColumn('assigned_class');
            });
        }

        if (Schema::hasTable('club_classes') && Schema::hasColumn('club_classes', 'assigned_staff_id')) {
            Schema::table('club_classes', function (Blueprint $table) {
                $table->dropForeign(['assigned_staff_id']);
                $table->dropColumn('assigned_staff_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('staff_adventurers') && !Schema::hasColumn('staff_adventurers', 'assigned_class')) {
            Schema::table('staff_adventurers', function (Blueprint $table) {
                $table->string('assigned_class')->nullable()->after('club_id');
            });
        }
        if (Schema::hasTable('club_classes') && !Schema::hasColumn('club_classes', 'assigned_staff_id')) {
            Schema::table('club_classes', function (Blueprint $table) {
                $table->unsignedBigInteger('assigned_staff_id')->nullable()->after('class_name');
                $table->foreign('assigned_staff_id')->references('id')->on('staff_adventurers')->onDelete('set null');
            });
        }
    }
};
