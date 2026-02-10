<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('plan_json');
            $table->text('ai_summary')->nullable();
            $table->json('missing_items_json')->nullable();
            $table->json('conversation_json')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();

            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_plans');
    }
};
