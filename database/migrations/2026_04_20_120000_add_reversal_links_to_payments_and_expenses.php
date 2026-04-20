<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payments') && !Schema::hasColumn('payments', 'reversed_payment_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('reversed_payment_id')->nullable()->after('notes');
                $table->foreign('reversed_payment_id')
                    ->references('id')
                    ->on('payments')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('expenses') && !Schema::hasColumn('expenses', 'reversed_expense_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('reversed_expense_id')->nullable()->after('settles_expense_id');
                $table->foreign('reversed_expense_id')
                    ->references('id')
                    ->on('expenses')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'reversed_payment_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['reversed_payment_id']);
                $table->dropColumn('reversed_payment_id');
            });
        }

        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'reversed_expense_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropForeign(['reversed_expense_id']);
                $table->dropColumn('reversed_expense_id');
            });
        }
    }
};
