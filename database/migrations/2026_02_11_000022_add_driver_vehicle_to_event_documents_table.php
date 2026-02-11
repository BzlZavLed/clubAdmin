<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_documents', function (Blueprint $table) {
            $table->foreignId('driver_participant_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('event_participants')
                ->nullOnDelete();
            $table->foreignId('vehicle_id')
                ->nullable()
                ->after('driver_participant_id')
                ->constrained('event_vehicles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_documents', function (Blueprint $table) {
            $table->dropForeign(['driver_participant_id']);
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn(['driver_participant_id', 'vehicle_id']);
        });
    }
};
