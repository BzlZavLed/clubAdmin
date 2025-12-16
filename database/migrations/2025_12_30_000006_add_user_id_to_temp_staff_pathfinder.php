<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('temp_staff_pathfinder') && !Schema::hasColumn('temp_staff_pathfinder', 'user_id')) {
            Schema::table('temp_staff_pathfinder', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('staff_id');
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('temp_staff_pathfinder') && Schema::hasColumn('temp_staff_pathfinder', 'user_id')) {
            Schema::table('temp_staff_pathfinder', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
