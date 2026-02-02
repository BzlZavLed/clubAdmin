<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payment_concepts') && Schema::hasColumn('payment_concepts', 'pay_to')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE payment_concepts ALTER COLUMN pay_to TYPE varchar(255) USING pay_to::text");
            } elseif ($driver === 'mysql') {
                DB::statement("ALTER TABLE payment_concepts MODIFY pay_to varchar(255)");
            }
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (!Schema::hasColumn('payments', 'concept_text')) {
                    $table->string('concept_text')->nullable()->after('payment_concept_id');
                }
                if (!Schema::hasColumn('payments', 'pay_to')) {
                    $table->string('pay_to')->nullable()->after('concept_text');
                }
            });

            if (Schema::hasColumn('payments', 'payment_concept_id')) {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement("ALTER TABLE payments ALTER COLUMN payment_concept_id DROP NOT NULL");
                } elseif ($driver === 'mysql') {
                    DB::statement("ALTER TABLE payments MODIFY payment_concept_id BIGINT UNSIGNED NULL");
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (Schema::hasColumn('payments', 'concept_text')) {
                    $table->dropColumn('concept_text');
                }
                if (Schema::hasColumn('payments', 'pay_to')) {
                    $table->dropColumn('pay_to');
                }
            });

            if (Schema::hasColumn('payments', 'payment_concept_id')) {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement("ALTER TABLE payments ALTER COLUMN payment_concept_id SET NOT NULL");
                } elseif ($driver === 'mysql') {
                    DB::statement("ALTER TABLE payments MODIFY payment_concept_id BIGINT UNSIGNED NOT NULL");
                }
            }
        }
    }
};
