<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('payment_concept_id')->nullable()->constrained('payment_concepts')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnUpdate()->nullOnDelete();
            $table->string('concept_text')->nullable();
            $table->string('pay_to')->nullable();
            $table->decimal('expected_amount', 10, 2)->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->string('payment_type', 32)->default('transfer');
            $table->string('reference', 120)->nullable();
            $table->string('receipt_image_path', 512);
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('approved_payment_id')->nullable()->constrained('payments')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_user_id', 'status']);
            $table->index(['club_id', 'status']);
            $table->index(['member_id', 'payment_concept_id', 'status'], 'pps_member_concept_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_payment_submissions');
    }
};
