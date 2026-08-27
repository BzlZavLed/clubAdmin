<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropForeign(['parent_user_id']);
            $table->dropForeign(['staff_user_id']);

            $table->foreign('parent_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('staff_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropForeign(['parent_user_id']);
            $table->dropForeign(['staff_user_id']);

            $table->foreign('parent_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('staff_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }
};
