<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('movement_type');
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('movement_date');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_original_name')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('event_club_settlement_id')->nullable()->constrained('event_club_settlements')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['club_id', 'movement_type']);
            $table->index(['club_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_movements');
    }
};
