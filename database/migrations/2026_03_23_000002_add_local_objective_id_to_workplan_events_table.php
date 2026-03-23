<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workplan_events', function (Blueprint $table) {
            $table->foreignId('local_objective_id')
                ->nullable()
                ->after('objective_id')
                ->constrained('club_objectives')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workplan_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('local_objective_id');
        });
    }
};
