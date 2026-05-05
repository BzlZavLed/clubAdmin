<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_club_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('organizer_scope_type');
            $table->unsignedBigInteger('organizer_scope_id');
            $table->decimal('amount', 10, 2);
            $table->json('breakdown_json');
            $table->timestamp('deposited_at');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('club_code', 12);
            $table->unsignedSmallInteger('receipt_year');
            $table->unsignedInteger('club_sequence');
            $table->string('receipt_number')->unique();
            $table->timestamp('issued_at');
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['event_id', 'club_id']);
            $table->unique(['club_id', 'receipt_year', 'club_sequence'], 'event_club_settlements_club_year_sequence_unique');
            $table->index(['club_id', 'deposited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_club_settlements');
    }
};
