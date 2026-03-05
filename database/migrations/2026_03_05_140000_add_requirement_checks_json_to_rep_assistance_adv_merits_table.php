<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            $table->json('requirement_checks_json')->nullable()->after('cuota_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rep_assistance_adv_merits', function (Blueprint $table) {
            $table->dropColumn('requirement_checks_json');
        });
    }
};

