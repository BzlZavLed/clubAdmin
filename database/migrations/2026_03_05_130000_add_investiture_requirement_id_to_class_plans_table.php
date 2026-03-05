<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_plans', function (Blueprint $table) {
            $table->foreignId('investiture_requirement_id')
                ->nullable()
                ->after('class_id')
                ->constrained('class_investiture_requirements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('class_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investiture_requirement_id');
        });
    }
};

