<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_club_settlements')) {
            return;
        }

        if (!Schema::hasIndex('event_club_settlements', ['event_id'])) {
            Schema::table('event_club_settlements', function (Blueprint $table) {
                $table->index('event_id', 'event_club_settlements_event_id_index');
            });
        }

        if (Schema::hasIndex('event_club_settlements', 'event_club_settlements_event_id_club_id_unique', 'unique')) {
            Schema::table('event_club_settlements', function (Blueprint $table) {
                $table->dropUnique('event_club_settlements_event_id_club_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('event_club_settlements')) {
            return;
        }

        if (!Schema::hasIndex('event_club_settlements', 'event_club_settlements_event_id_club_id_unique', 'unique')) {
            Schema::table('event_club_settlements', function (Blueprint $table) {
                $table->unique(['event_id', 'club_id']);
            });
        }

        if (Schema::hasIndex('event_club_settlements', 'event_club_settlements_event_id_index')) {
            Schema::table('event_club_settlements', function (Blueprint $table) {
                $table->dropIndex('event_club_settlements_event_id_index');
            });
        }
    }
};
