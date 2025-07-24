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
            $table->unsignedInteger('total')->default(0)->after('cuota');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            $table->dropColumn('total');

        });
    }
};
