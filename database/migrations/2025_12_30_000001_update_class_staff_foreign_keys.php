<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Update club_classes.assigned_staff_id to reference staff.id
        if (Schema::hasTable('club_classes') && Schema::hasColumn('club_classes', 'assigned_staff_id')) {
            // Drop existing FK (likely pointing to staff_adventurers). Use IF EXISTS for Postgres compatibility.
            try {
                DB::statement('ALTER TABLE club_classes DROP CONSTRAINT IF EXISTS club_classes_assigned_staff_id_foreign');
            } catch (\Throwable $e) {
                // ignore
            }

            // Null any values that do not exist in staff table to avoid FK violations
            if (Schema::hasTable('staff')) {
                DB::table('club_classes')
                    ->whereNotIn('assigned_staff_id', function ($query) {
                        $query->select('id')->from('staff');
                    })
                    ->update(['assigned_staff_id' => null]);
            }

            Schema::table('club_classes', function (Blueprint $table) {
                $table->foreign('assigned_staff_id')
                    ->references('id')
                    ->on('staff')
                    ->nullOnDelete();
            });
        }

        // Update members.assigned_staff_id (if column exists) to reference staff.id
        if (Schema::hasTable('members') && Schema::hasColumn('members', 'assigned_staff_id')) {
            // Safer drop for Postgres/MySQL using IF EXISTS
            try {
                DB::statement('ALTER TABLE members DROP CONSTRAINT IF EXISTS members_assigned_staff_id_foreign');
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasTable('staff')) {
                DB::table('members')
                    ->whereNotIn('assigned_staff_id', function ($query) {
                        $query->select('id')->from('staff');
                    })
                    ->update(['assigned_staff_id' => null]);
            }

            Schema::table('members', function (Blueprint $table) {
                $table->foreign('assigned_staff_id')
                    ->references('id')
                    ->on('staff')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Best-effort rollback to previous state (drop FKs to staff)
        if (Schema::hasTable('club_classes') && Schema::hasColumn('club_classes', 'assigned_staff_id')) {
            Schema::table('club_classes', function (Blueprint $table) {
                try {
                    $table->dropForeign(['assigned_staff_id']);
                } catch (\Throwable $e) {
                }
            });
        }
        if (Schema::hasTable('members') && Schema::hasColumn('members', 'assigned_staff_id')) {
            Schema::table('members', function (Blueprint $table) {
                try {
                    $table->dropForeign(['assigned_staff_id']);
                } catch (\Throwable $e) {
                }
            });
        }
    }
};
