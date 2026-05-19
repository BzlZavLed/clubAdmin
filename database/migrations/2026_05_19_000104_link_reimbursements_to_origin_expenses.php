<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'reimbursement_origin_expense_id')) {
                $table->unsignedBigInteger('reimbursement_origin_expense_id')->nullable()->after('settles_expense_id');
                $table->foreign('reimbursement_origin_expense_id', 'expenses_reimbursement_origin_fk')
                    ->references('id')
                    ->on('expenses')
                    ->nullOnDelete();
            }
        });

        $reimbursements = DB::table('expenses')
            ->where('pay_to', 'reimbursement_to')
            ->whereNull('settles_expense_id')
            ->whereNull('reimbursement_origin_expense_id')
            ->orderBy('id')
            ->get(['id', 'club_id', 'expense_date', 'created_by_user_id', 'created_at']);

        foreach ($reimbursements as $reimbursement) {
            $origin = DB::table('expenses')
                ->where('club_id', $reimbursement->club_id)
                ->where('pay_to', '!=', 'reimbursement_to')
                ->whereDate('expense_date', $reimbursement->expense_date)
                ->whereNull('settles_expense_id')
                ->when($reimbursement->created_by_user_id, fn ($query) => $query->where('created_by_user_id', $reimbursement->created_by_user_id))
                ->where('id', '<', $reimbursement->id)
                ->orderByDesc('id')
                ->first(['id']);

            if ($origin) {
                DB::table('expenses')
                    ->where('id', $reimbursement->id)
                    ->update(['reimbursement_origin_expense_id' => $origin->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'reimbursement_origin_expense_id')) {
                $table->dropForeign('expenses_reimbursement_origin_fk');
                $table->dropColumn('reimbursement_origin_expense_id');
            }
        });
    }
};
