<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('funds_location')->nullable()->after('pay_to');
            $table->index(['club_id', 'pay_to', 'funds_location']);
        });

        DB::table('expenses')
            ->whereNull('funds_location')
            ->where('pay_to', '!=', 'reimbursement_to')
            ->update(['funds_location' => 'cash']);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'pay_to', 'funds_location']);
            $table->dropColumn('funds_location');
        });
    }
};
