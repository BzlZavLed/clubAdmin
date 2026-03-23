<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_objectives', function (Blueprint $table) {
            $table->string('annual_evaluation_metric')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('club_objectives', function (Blueprint $table) {
            $table->dropColumn('annual_evaluation_metric');
        });
    }
};
