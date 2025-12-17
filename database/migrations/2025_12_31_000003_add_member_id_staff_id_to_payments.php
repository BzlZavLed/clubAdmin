<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'member_id')) {
                $table->unsignedBigInteger('member_id')->nullable()->after('payment_concept_id');
            }
            if (!Schema::hasColumn('payments', 'staff_id')) {
                $table->unsignedBigInteger('staff_id')->nullable()->after('member_id');
            }
        });

        // Backfill from legacy columns when present.
        // - member_adventurer_id => members(type=adventurers).id_data
        // - staff_adventurer_id  => staff(type=adventurers).id_data
        try {
            if (Schema::hasColumn('payments', 'member_adventurer_id')) {
                DB::statement("
                    update payments
                    set member_id = (
                        select m.id
                        from members m
                        where m.type = 'adventurers'
                          and m.id_data = payments.member_adventurer_id
                        limit 1
                    )
                    where member_id is null
                      and payments.member_adventurer_id is not null
                ");
            }

            if (Schema::hasColumn('payments', 'staff_adventurer_id')) {
                DB::statement("
                    update payments
                    set staff_id = (
                        select s.id
                        from staff s
                        where s.type = 'adventurers'
                          and s.id_data = payments.staff_adventurer_id
                        limit 1
                    )
                    where staff_id is null
                      and payments.staff_adventurer_id is not null
                ");
            }
        } catch (\Throwable $e) {
            // ignore (different SQL dialects / permissions)
        }

        Schema::table('payments', function (Blueprint $table) {
            try {
                $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            } catch (\Throwable $e) {
            }
            try {
                $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
            } catch (\Throwable $e) {
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'member_id')) {
                try {
                    $table->dropForeign(['member_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('member_id');
            }
            if (Schema::hasColumn('payments', 'staff_id')) {
                try {
                    $table->dropForeign(['staff_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('staff_id');
            }
        });
    }
};

