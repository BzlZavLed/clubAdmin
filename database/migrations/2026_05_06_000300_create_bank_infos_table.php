<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_infos', function (Blueprint $table) {
            $table->id();
            $table->morphs('bankable');
            $table->string('pay_to');
            $table->string('label')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_holder')->nullable();
            $table->string('account_type')->nullable();
            $table->text('account_number')->nullable();
            $table->text('routing_number')->nullable();
            $table->string('zelle_email')->nullable();
            $table->string('zelle_phone')->nullable();
            $table->text('deposit_instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_parent_deposits')->default(false);
            $table->boolean('accepts_event_deposits')->default(false);
            $table->boolean('requires_receipt_upload')->default(true);
            $table->timestamps();

            $table->unique(['bankable_type', 'bankable_id', 'pay_to'], 'bank_infos_owner_pay_to_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_infos');
    }
};
