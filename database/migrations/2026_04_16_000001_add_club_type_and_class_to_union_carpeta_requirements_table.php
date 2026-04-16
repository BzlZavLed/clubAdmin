<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('union_carpeta_requirements', function (Blueprint $table) {
            $table->string('club_type')->default('pathfinders')->after('description');
            $table->string('class_name')->default('General')->after('club_type');
        });
    }

    public function down(): void
    {
        Schema::table('union_carpeta_requirements', function (Blueprint $table) {
            $table->dropColumn(['club_type', 'class_name']);
        });
    }
};
