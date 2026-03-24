<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'event_id')) {
                $table->foreignId('event_id')->nullable()->after('club_id')->constrained('events')->nullOnDelete();
            }
        });

        Schema::table('event_budget_items', function (Blueprint $table) {
            if (!Schema::hasColumn('event_budget_items', 'expense_id')) {
                $table->foreignId('expense_id')->nullable()->after('event_id')->constrained('expenses')->nullOnDelete();
            }
            if (!Schema::hasColumn('event_budget_items', 'expense_date')) {
                $table->date('expense_date')->nullable()->after('funding_source');
            }
            if (!Schema::hasColumn('event_budget_items', 'receipt_path')) {
                $table->string('receipt_path')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_budget_items', function (Blueprint $table) {
            if (Schema::hasColumn('event_budget_items', 'expense_id')) {
                $table->dropConstrainedForeignId('expense_id');
            }
            if (Schema::hasColumn('event_budget_items', 'expense_date')) {
                $table->dropColumn('expense_date');
            }
            if (Schema::hasColumn('event_budget_items', 'receipt_path')) {
                $table->dropColumn('receipt_path');
            }
        });

        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'event_id')) {
                $table->dropConstrainedForeignId('event_id');
            }
        });
    }
};
