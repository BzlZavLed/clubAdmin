<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_concepts DROP CONSTRAINT IF EXISTS payment_concepts_pay_to_check');
        }
    }

    public function down(): void
    {
        // No-op: restoring the old check constraint is not safe without knowing original enum list.
    }
};
