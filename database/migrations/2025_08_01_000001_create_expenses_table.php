<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->string('pay_to');
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('club_id')->references('id')->on('clubs')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
