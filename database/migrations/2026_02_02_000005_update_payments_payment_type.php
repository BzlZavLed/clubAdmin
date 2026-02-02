<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'payment_type')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_type_check');
                DB::statement("ALTER TABLE payments ALTER COLUMN payment_type TYPE varchar(32) USING payment_type::text");
            } elseif ($driver === 'mysql') {
                DB::statement("ALTER TABLE payments MODIFY payment_type varchar(32)");
            }
        }
    }

    public function down(): void
    {
        // No-op: restoring enum/check is not safe without the original definition.
    }
};
