<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('payment_concept_id');

            $table->unsignedBigInteger('member_adventurer_id')->nullable();
            $table->unsignedBigInteger('staff_adventurer_id')->nullable();

            // money
            $table->decimal('amount_paid', 10, 2);
            $table->decimal('expected_amount', 10, 2)->nullable(); // snapshot from concept at time of payment
            $table->decimal('balance_due_after', 10, 2)->nullable(); // remaining after this payment

            // when/how
            $table->date('payment_date')->index();
            $table->enum('payment_type', ['zelle', 'cash', 'check']);
            $table->string('zelle_phone', 32)->nullable();
            $table->string('check_image_path', 512)->nullable();

            // audit
            $table->unsignedBigInteger('received_by_user_id');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // FKs / indexes (same as before)...
            $table->foreign('club_id')->references('id')->on('clubs')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('payment_concept_id')->references('id')->on('payment_concepts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('member_adventurer_id')->references('id')->on('members_adventurers')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('staff_adventurer_id')->references('id')->on('staff_adventurers')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('received_by_user_id')->references('id')->on('users')->cascadeOnUpdate()->restrictOnDelete();

            $table->index(['club_id', 'payment_concept_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

