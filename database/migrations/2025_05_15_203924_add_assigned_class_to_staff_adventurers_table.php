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
        Schema::table('staff_adventurers', function (Blueprint $table) {
            $table->string('assigned_class')->nullable()->after('club_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_adventurers', function (Blueprint $table) {
            $table->dropColumn('assigned_class');
        });
    }
};
