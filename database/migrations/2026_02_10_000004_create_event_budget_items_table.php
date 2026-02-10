<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('category');
            $table->string('description');
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('funding_source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_budget_items');
    }
};
