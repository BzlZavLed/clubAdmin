<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fundraiser_events')) {
            Schema::table('fundraiser_events', function (Blueprint $table) {
                if (!Schema::hasColumn('fundraiser_events', 'investment_expense_id')) {
                    $table->foreignId('investment_expense_id')
                        ->nullable()
                        ->constrained('expenses')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn('fundraiser_events', 'investment_pay_to')) {
                    $table->string('investment_pay_to')->nullable();
                }

                if (!Schema::hasColumn('fundraiser_events', 'investment_funds_location')) {
                    $table->string('investment_funds_location', 16)->nullable();
                }
            });
        }

        if (Schema::hasTable('fundraiser_products')) {
            Schema::table('fundraiser_products', function (Blueprint $table) {
                if (!Schema::hasColumn('fundraiser_products', 'investment_expense_id')) {
                    $table->foreignId('investment_expense_id')
                        ->nullable()
                        ->constrained('expenses')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally left blank. These columns are part of the canonical
        // fundraiser schema; this migration only backfills older local DBs.
    }
};
