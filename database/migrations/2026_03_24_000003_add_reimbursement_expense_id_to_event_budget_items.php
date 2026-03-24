<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_budget_items', function (Blueprint $table) {
            if (!Schema::hasColumn('event_budget_items', 'reimbursement_expense_id')) {
                $table->foreignId('reimbursement_expense_id')
                    ->nullable()
                    ->after('expense_id')
                    ->constrained('expenses')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_budget_items', function (Blueprint $table) {
            if (Schema::hasColumn('event_budget_items', 'reimbursement_expense_id')) {
                $table->dropConstrainedForeignId('reimbursement_expense_id');
            }
        });
    }
};
