<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('event_type');
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->string('timezone')->default('America/New_York');
            $table->string('location_name')->nullable();
            $table->string('location_address')->nullable();
            $table->string('status')->default('draft');
            $table->decimal('budget_estimated_total', 10, 2)->nullable();
            $table->decimal('budget_actual_total', 10, 2)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->string('risk_level')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['club_id', 'start_at', 'status']);
            $table->index('event_type');
            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
