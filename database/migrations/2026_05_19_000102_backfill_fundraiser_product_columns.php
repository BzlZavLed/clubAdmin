<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fundraiser_products')) {
            return;
        }

        Schema::table('fundraiser_products', function (Blueprint $table) {
            if (!Schema::hasColumn('fundraiser_products', 'description')) {
                $table->text('description')->nullable()->after('name');
            }

            if (!Schema::hasColumn('fundraiser_products', 'unit_cost')) {
                $table->decimal('unit_cost', 10, 2)->default(0)->after('sale_price');
            }

            if (!Schema::hasColumn('fundraiser_products', 'investment_amount')) {
                $table->decimal('investment_amount', 10, 2)->default(0)->after('unit_cost');
            }

            if (!Schema::hasColumn('fundraiser_products', 'investment_expense_id')) {
                $table->foreignId('investment_expense_id')
                    ->nullable()
                    ->after('investment_amount')
                    ->constrained('expenses')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('fundraiser_products', 'tracks_inventory')) {
                $table->boolean('tracks_inventory')->default(false)->after('investment_expense_id');
            }

            if (!Schema::hasColumn('fundraiser_products', 'quantity_available')) {
                $table->unsignedInteger('quantity_available')->nullable()->after('tracks_inventory');
            }

            if (!Schema::hasColumn('fundraiser_products', 'quantity_sold')) {
                $table->unsignedInteger('quantity_sold')->default(0)->after('quantity_available');
            }

            if (!Schema::hasColumn('fundraiser_products', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('quantity_sold');
            }
        });
    }

    public function down(): void
    {
        // Compatibility-only migration for older local fundraiser tables.
    }
};
