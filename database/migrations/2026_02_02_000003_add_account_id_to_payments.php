<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'account_id')) {
                $table->unsignedBigInteger('account_id')->nullable()->after('pay_to');
            }
        });

        // Backfill account_id from pay_to + club_id
        try {
            DB::statement("
                update payments
                set account_id = (
                    select a.id
                    from accounts a
                    where a.club_id = payments.club_id
                      and a.pay_to = payments.pay_to
                    limit 1
                )
                where account_id is null
                  and payments.pay_to is not null
            ");
        } catch (\Throwable $e) {
            // ignore (dialect/permission)
        }

        Schema::table('payments', function (Blueprint $table) {
            try {
                $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            } catch (\Throwable $e) {
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'account_id')) {
                try {
                    $table->dropForeign(['account_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('account_id');
            }
        });
    }
};
