<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // In PostgreSQL, dropping the column will also drop dependent indexes/constraints.
        // We intentionally avoid dropForeign/dropIndex here because those names vary across environments.
        if (Schema::hasColumn('payments', 'member_adventurer_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('member_adventurer_id');
            });
        }

        if (Schema::hasColumn('payments', 'staff_adventurer_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('staff_adventurer_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'member_adventurer_id')) {
                $table->unsignedBigInteger('member_adventurer_id')->nullable();
            }
            if (!Schema::hasColumn('payments', 'staff_adventurer_id')) {
                $table->unsignedBigInteger('staff_adventurer_id')->nullable();
            }
        });
    }
};
