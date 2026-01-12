<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workplan_events', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('location');
            $table->unsignedBigInteger('objective_id')->nullable()->after('department_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workplan_events', function (Blueprint $table) {
            $table->dropColumn(['department_id', 'objective_id']);
        });
    }
};
