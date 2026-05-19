<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundraiser_event_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_event_id')->constrained('fundraiser_events')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('partner_club_id')->constrained('clubs')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('investment_share_percent', 5, 2)->default(0);
            $table->decimal('earnings_share_percent', 5, 2)->default(0);
            $table->string('status', 32)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['fundraiser_event_id', 'partner_club_id'], 'fundraiser_partner_unique_club');
        });

        Schema::create('fundraiser_partner_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_event_partner_id')->constrained('fundraiser_event_partners')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('transfer_type', 48)->index();
            $table->foreignId('from_club_id')->constrained('clubs')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('to_club_id')->constrained('clubs')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('from_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('to_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('from_pay_to')->default('club_budget');
            $table->string('to_pay_to')->default('club_budget');
            $table->string('funds_location', 16)->default('cash');
            $table->string('payment_type', 32)->default('transfer');
            $table->decimal('amount', 10, 2);
            $table->date('transfer_date')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fundraiser_event_partner_id', 'transfer_type'], 'fundraiser_partner_transfer_once');
            $table->index(['from_club_id', 'transfer_date']);
            $table->index(['to_club_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fundraiser_partner_transfers');
        Schema::dropIfExists('fundraiser_event_partners');
    }
};
