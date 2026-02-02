<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Backfill payments.pay_to from payment_concepts when missing
        try {
            DB::statement("
                update payments
                set pay_to = (
                    select pc.pay_to
                    from payment_concepts pc
                    where pc.id = payments.payment_concept_id
                    limit 1
                )
                where payments.pay_to is null
                  and payments.payment_concept_id is not null
            ");
        } catch (\Throwable $e) {
            // ignore
        }

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
                where payments.account_id is null
                  and payments.pay_to is not null
            ");
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function down(): void
    {
        // No-op
    }
};
