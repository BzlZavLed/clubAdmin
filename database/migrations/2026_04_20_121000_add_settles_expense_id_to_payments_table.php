<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payments') && !Schema::hasColumn('payments', 'settles_expense_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('settles_expense_id')->nullable()->after('reversed_payment_id');
                $table->foreign('settles_expense_id')
                    ->references('id')
                    ->on('expenses')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'settles_expense_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['settles_expense_id']);
                $table->dropColumn('settles_expense_id');
            });
        }
    }
};
