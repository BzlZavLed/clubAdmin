<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('class_plans')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Postgres enum created via CHECK constraint in earlier migration.
            DB::statement("ALTER TABLE class_plans DROP CONSTRAINT IF EXISTS class_plans_status_check;");
            DB::statement("ALTER TABLE class_plans ADD CONSTRAINT class_plans_status_check CHECK (status IN ('draft','submitted','approved','rejected','changes_requested'));");
        } elseif ($driver === 'mysql') {
            // MySQL: widen the column to allow the new status, no check constraint.
            Schema::table('class_plans', function ($table) {
                $table->string('status', 32)->default('draft')->change();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('class_plans')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE class_plans DROP CONSTRAINT IF EXISTS class_plans_status_check;");
            DB::statement("ALTER TABLE class_plans ADD CONSTRAINT class_plans_status_check CHECK (status IN ('draft','submitted','approved','rejected'));");
        } elseif ($driver === 'mysql') {
            Schema::table('class_plans', function ($table) {
                $table->string('status', 32)->default('draft')->change();
            });
        }
    }
};
