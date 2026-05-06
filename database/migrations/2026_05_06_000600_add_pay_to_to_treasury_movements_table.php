<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_movements', function (Blueprint $table) {
            $table->string('pay_to')->default('club_budget')->after('club_id');
            $table->index(['club_id', 'pay_to']);
        });
    }

    public function down(): void
    {
        Schema::table('treasury_movements', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'pay_to']);
            $table->dropColumn('pay_to');
        });
    }
};
