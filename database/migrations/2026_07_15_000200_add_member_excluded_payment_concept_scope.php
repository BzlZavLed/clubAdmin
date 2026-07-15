<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE payment_concept_scopes DROP CONSTRAINT IF EXISTS payment_concept_scopes_scope_type_check');
            DB::statement("ALTER TABLE payment_concept_scopes ADD CONSTRAINT payment_concept_scopes_scope_type_check CHECK (scope_type IN ('club_wide', 'class', 'member', 'member_excluded', 'staff_wide', 'staff'))");
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
