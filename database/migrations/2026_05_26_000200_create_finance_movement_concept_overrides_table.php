<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_movement_concept_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type', 32);
            $table->unsignedBigInteger('movement_id');
            $table->text('original_concept')->nullable();
            $table->text('display_concept');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['club_id', 'movement_type', 'movement_id'], 'finance_movement_concept_override_unique');
            $table->index(['club_id', 'movement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_movement_concept_overrides');
    }
};
