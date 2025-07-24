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
        Schema::table('rep_assistance_adv', function (Blueprint $table) {
            if (Schema::hasColumn('rep_assistance_adv', 'applicant_name')) {
                $table->dropColumn('applicant_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rep_assistance_adv', function (Blueprint $table) {
            $table->string('applicant_name')->nullable();
        });
    }
};
