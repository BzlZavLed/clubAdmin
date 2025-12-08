<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Postgres enum created via CHECK constraint in earlier migration.
        if (Schema::hasTable('class_plans')) {
            DB::statement("ALTER TABLE class_plans DROP CONSTRAINT IF EXISTS class_plans_status_check;");
            DB::statement("ALTER TABLE class_plans ADD CONSTRAINT class_plans_status_check CHECK (status IN ('draft','submitted','approved','rejected','changes_requested'));");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('class_plans')) {
            DB::statement("ALTER TABLE class_plans DROP CONSTRAINT IF EXISTS class_plans_status_check;");
            DB::statement("ALTER TABLE class_plans ADD CONSTRAINT class_plans_status_check CHECK (status IN ('draft','submitted','approved','rejected'));");
        }
    }
};
