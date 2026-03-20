<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments');
            $table->foreignId('club_id')->constrained('clubs');
            $table->foreignId('member_id')->nullable()->constrained('members');
            $table->foreignId('staff_id')->nullable()->constrained('staff');
            $table->foreignId('parent_user_id')->nullable()->constrained('users');
            $table->foreignId('staff_user_id')->nullable()->constrained('users');
            $table->string('receipt_number')->unique();
            $table->string('issued_to_type')->nullable();
            $table->string('issued_to_email')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
