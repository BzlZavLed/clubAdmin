<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('treasury_movements', 'from_pay_to')) {
                $table->string('from_pay_to')->nullable()->after('pay_to');
            }

            if (!Schema::hasColumn('treasury_movements', 'to_pay_to')) {
                $table->string('to_pay_to')->nullable()->after('from_pay_to');
            }
        });

        Schema::table('treasury_movements', function (Blueprint $table) {
            $table->index(['club_id', 'from_pay_to'], 'tm_club_from_pay_idx');
            $table->index(['club_id', 'to_pay_to'], 'tm_club_to_pay_idx');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_movements', function (Blueprint $table) {
            $table->dropIndex('tm_club_from_pay_idx');
            $table->dropIndex('tm_club_to_pay_idx');
        });

        Schema::table('treasury_movements', function (Blueprint $table) {
            if (Schema::hasColumn('treasury_movements', 'to_pay_to')) {
                $table->dropColumn('to_pay_to');
            }

            if (Schema::hasColumn('treasury_movements', 'from_pay_to')) {
                $table->dropColumn('from_pay_to');
            }
        });
    }
};
