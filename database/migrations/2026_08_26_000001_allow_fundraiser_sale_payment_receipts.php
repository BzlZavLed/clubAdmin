<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->unsignedBigInteger('payment_id')->nullable()->change();
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreignId('fundraiser_sale_id')
                ->nullable()
                ->unique()
                ->after('payment_id')
                ->constrained('fundraiser_sales');
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fundraiser_sale_id');
            $table->dropForeign(['payment_id']);
            $table->unsignedBigInteger('payment_id')->nullable(false)->change();
            $table->foreign('payment_id')->references('id')->on('payments');
        });
    }
};
