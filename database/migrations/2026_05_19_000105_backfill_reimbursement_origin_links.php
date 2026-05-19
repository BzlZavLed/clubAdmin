<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('expenses', 'reimbursement_origin_expense_id')) {
            return;
        }

        $this->backfillPendingReimbursements();
        $this->backfillSettlementExpenses();
    }

    public function down(): void
    {
        // Data-only backfill. Do not clear links because users may have relied on them after migration.
    }

    private function backfillPendingReimbursements(): void
    {
        $usedOriginIds = DB::table('expenses')
            ->where('pay_to', 'reimbursement_to')
            ->whereNull('settles_expense_id')
            ->whereNotNull('reimbursement_origin_expense_id')
            ->pluck('reimbursement_origin_expense_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        $reimbursements = DB::table('expenses')
            ->where('pay_to', 'reimbursement_to')
            ->whereNull('settles_expense_id')
            ->whereNull('reimbursement_origin_expense_id')
            ->orderBy('id')
            ->get([
                'id',
                'club_id',
                'expense_date',
                'description',
                'created_by_user_id',
                'created_at',
            ]);

        foreach ($reimbursements as $reimbursement) {
            $origin = $this->findOriginExpense($reimbursement, $usedOriginIds);

            if (!$origin) {
                continue;
            }

            DB::table('expenses')
                ->where('id', $reimbursement->id)
                ->update(['reimbursement_origin_expense_id' => $origin->id]);

            $usedOriginIds[] = (int) $origin->id;
        }
    }

    private function findOriginExpense(object $reimbursement, array $usedOriginIds): ?object
    {
        $candidates = DB::table('expenses')
            ->where('club_id', $reimbursement->club_id)
            ->where('pay_to', '!=', 'reimbursement_to')
            ->whereNull('settles_expense_id')
            ->where('id', '<', $reimbursement->id)
            ->whereDate('expense_date', $reimbursement->expense_date)
            ->when($reimbursement->created_by_user_id, fn ($query) => $query->where('created_by_user_id', $reimbursement->created_by_user_id))
            ->when(!empty($usedOriginIds), fn ($query) => $query->whereNotIn('id', $usedOriginIds))
            ->orderByDesc('id')
            ->get(['id', 'description', 'created_at']);

        if ($candidates->isEmpty()) {
            return null;
        }

        $description = trim((string) $reimbursement->description);
        $descriptionPrefix = 'Reembolso pendiente por: ';
        if (str_starts_with($description, $descriptionPrefix)) {
            $originDescription = trim(substr($description, strlen($descriptionPrefix)));
            $matchingDescription = $candidates->first(fn ($candidate) => trim((string) $candidate->description) === $originDescription);

            if ($matchingDescription) {
                return $matchingDescription;
            }
        }

        $createdAt = $this->parseDate($reimbursement->created_at);
        $nearbyCandidates = $candidates->filter(function ($candidate) use ($createdAt) {
            $candidateCreatedAt = $this->parseDate($candidate->created_at);

            return $createdAt
                && $candidateCreatedAt
                && abs($createdAt->diffInSeconds($candidateCreatedAt, false)) <= 600;
        });

        return $nearbyCandidates->count() === 1 ? $nearbyCandidates->first() : null;
    }

    private function backfillSettlementExpenses(): void
    {
        $settlements = DB::table('expenses')
            ->whereNotNull('settles_expense_id')
            ->whereNull('reimbursement_origin_expense_id')
            ->orderBy('id')
            ->get(['id', 'settles_expense_id']);

        foreach ($settlements as $settlement) {
            $originId = DB::table('expenses')
                ->where('id', $settlement->settles_expense_id)
                ->value('reimbursement_origin_expense_id');

            if (!$originId) {
                continue;
            }

            DB::table('expenses')
                ->where('id', $settlement->id)
                ->update(['reimbursement_origin_expense_id' => $originId]);
        }
    }

    private function parseDate($value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
};
