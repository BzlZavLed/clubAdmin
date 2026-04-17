<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'settles_expense_id')) {
                $table->unsignedBigInteger('settles_expense_id')->nullable()->after('reimbursement_receipt_path');
                $table->foreign('settles_expense_id')->references('id')->on('expenses')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'settles_expense_id')) {
                $table->dropForeign(['settles_expense_id']);
                $table->dropColumn('settles_expense_id');
            }
        });
    }
};
