<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            $table->decimal('cuota_amount', 8, 2)->nullable()->after('cuota');
        });
    }

    public function down(): void
    {
        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            $table->dropColumn('cuota_amount');
        });
    }
};
