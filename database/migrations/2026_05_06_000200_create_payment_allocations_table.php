<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_concept_id')->constrained('payment_concepts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('event_fee_component_id')->nullable()->constrained('event_fee_components')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index(['payment_concept_id', 'event_fee_component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
