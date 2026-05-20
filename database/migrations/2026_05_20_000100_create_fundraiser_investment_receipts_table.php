<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundraiser_investment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_event_id')->constrained('fundraiser_events')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('path', 512);
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fundraiser_event_id', 'expense_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fundraiser_investment_receipts');
    }
};
