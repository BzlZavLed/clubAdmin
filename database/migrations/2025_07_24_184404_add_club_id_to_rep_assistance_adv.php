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
            $table->unsignedBigInteger('club_id')->nullable()->after('church_id');

            // Optional: Add foreign key constraint if needed
            $table->foreign('club_id')->references('id')->on('clubs')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rep_assistance_adv', function (Blueprint $table) {
            $table->dropColumn('club_id');
        });
    }
};
