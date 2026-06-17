<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pathfinder_annual_applications', function (Blueprint $table) {
            $table->string('director_phone_number')->nullable()->after('mailing_address');
        });
    }

    public function down(): void
    {
        Schema::table('pathfinder_annual_applications', function (Blueprint $table) {
            $table->dropColumn('director_phone_number');
        });
    }
};
