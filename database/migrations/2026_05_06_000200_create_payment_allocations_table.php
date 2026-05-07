<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_allocations')) {
            $this->ensureIndexes();

            return;
        }

        try {
            Schema::create('payment_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
                $table->foreignId('payment_concept_id')->constrained('payment_concepts')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('event_fee_component_id')->nullable()->constrained('event_fee_components')->nullOnDelete();
                $table->decimal('amount', 10, 2);
                $table->timestamps();

                $table->index(['payment_concept_id', 'event_fee_component_id'], 'pay_alloc_concept_component_idx');
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1050) {
                throw $e;
            }

            $this->ensureIndexes();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }

    private function ensureIndexes(): void
    {
        $indexName = 'pay_alloc_concept_component_idx';

        if (!Schema::hasIndex('payment_allocations', $indexName)) {
            Schema::table('payment_allocations', function (Blueprint $table) use ($indexName) {
                $table->index(['payment_concept_id', 'event_fee_component_id'], $indexName);
            });
        }
    }
};
