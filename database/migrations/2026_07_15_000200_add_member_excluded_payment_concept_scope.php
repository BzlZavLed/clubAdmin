<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $column = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', 'payment_concept_scopes')
                ->where('column_name', 'scope_type')
                ->first(['data_type', 'udt_name']);

            // Older production databases may use a native enum, while newer
            // ones use varchar plus a check constraint.
            if (($column->data_type ?? null) === 'USER-DEFINED' && !empty($column->udt_name)) {
                $type = '"' . str_replace('"', '""', $column->udt_name) . '"';
                DB::statement("ALTER TYPE {$type} ADD VALUE IF NOT EXISTS 'member_excluded'");
            }

            DB::statement('ALTER TABLE payment_concept_scopes DROP CONSTRAINT IF EXISTS payment_concept_scopes_scope_type_check');
            DB::statement("ALTER TABLE payment_concept_scopes ADD CONSTRAINT payment_concept_scopes_scope_type_check CHECK ((scope_type)::text IN ('club_wide', 'class', 'member', 'member_excluded', 'staff_wide', 'staff'))");
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE payment_concept_scopes MODIFY scope_type ENUM('club_wide', 'class', 'member', 'member_excluded', 'staff_wide', 'staff') NOT NULL");
        }
    }

    public function down(): void
    {
        // Do not remove the type while exclusion rows may exist.
    }
};
