<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_concept_id')->nullable()->after('pay_to');
            $table->unsignedBigInteger('payee_id')->nullable()->after('payment_concept_id');

            $table->foreign('payment_concept_id')->references('id')->on('payment_concepts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['payment_concept_id']);
            $table->dropColumn(['payment_concept_id', 'payee_id']);
        });
    }
};
