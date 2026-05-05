<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('scope_type', 20)->default('club')->after('club_id');
            $table->unsignedBigInteger('scope_id')->nullable()->after('scope_type');
            $table->index(['scope_type', 'scope_id'], 'events_scope_lookup_idx');
        });

        Schema::create('event_target_club', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'club_id'], 'event_target_club_unique');
            $table->index('club_id');
        });

        DB::table('events')
            ->select('id', 'club_id')
            ->orderBy('id')
            ->get()
            ->each(function ($event): void {
                DB::table('events')
                    ->where('id', $event->id)
                    ->update([
                        'scope_type' => 'club',
                        'scope_id' => $event->club_id,
                    ]);

                DB::table('event_target_club')->insert([
                    'event_id' => $event->id,
                    'club_id' => $event->club_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_target_club');

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_scope_lookup_idx');
            $table->dropColumn(['scope_type', 'scope_id']);
        });
    }
};
