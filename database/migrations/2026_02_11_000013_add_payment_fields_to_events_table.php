<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_payable')->default(false)->after('requires_approval');
            $table->decimal('payment_amount', 10, 2)->nullable()->after('is_payable');
            $table->foreignId('payment_concept_id')
                ->nullable()
                ->after('payment_amount')
                ->constrained('payment_concepts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['payment_concept_id']);
            $table->dropColumn(['payment_concept_id', 'payment_amount', 'is_payable']);
        });
    }
};
