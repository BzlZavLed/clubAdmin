<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundraiser_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('fundraiser_type', 32)->default('products');
            $table->date('event_date')->nullable()->index();
            $table->string('pay_to')->default('club_budget')->index();
            $table->decimal('investment_total', 10, 2)->default(0);
            $table->foreignId('investment_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('investment_pay_to')->nullable();
            $table->string('investment_funds_location', 16)->nullable();
            $table->unsignedInteger('planned_units')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['club_id', 'status']);
        });

        Schema::create('fundraiser_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_event_id')->constrained('fundraiser_events')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('sale_price', 10, 2);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('investment_amount', 10, 2)->default(0);
            $table->foreignId('investment_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->boolean('tracks_inventory')->default(false);
            $table->unsignedInteger('quantity_available')->nullable();
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fundraiser_event_id', 'is_active']);
        });

        Schema::create('fundraiser_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_event_id')->constrained('fundraiser_events')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->date('sale_date')->index();
            $table->string('payment_type', 32);
            $table->string('zelle_phone', 32)->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->decimal('total_cost', 10, 2)->default(0);
            $table->decimal('gain_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['club_id', 'sale_date']);
            $table->index(['fundraiser_event_id', 'sale_date']);
        });

        Schema::create('fundraiser_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_sale_id')->constrained('fundraiser_sales')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('fundraiser_product_id')->nullable()->constrained('fundraiser_products')->nullOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2);
            $table->decimal('line_cost', 10, 2)->default(0);
            $table->decimal('line_gain', 10, 2)->default(0);
            $table->timestamps();

            $table->index('fundraiser_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fundraiser_sale_items');
        Schema::dropIfExists('fundraiser_sales');
        Schema::dropIfExists('fundraiser_products');
        Schema::dropIfExists('fundraiser_events');
    }
};
