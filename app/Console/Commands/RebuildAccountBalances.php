<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Club;
use App\Models\Expense;
use App\Models\PayToOption;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildAccountBalances extends Command
{
    protected $signature = 'accounts:rebuild-balances';

    protected $description = 'Recalculate account balances from payments and expenses (run once after introducing accounts)';

    public function handle(): int
    {
        $this->info('Rebuilding account balances…');

        $clubs = Club::all(['id', 'club_name']);

        foreach ($clubs as $club) {
            DB::transaction(function () use ($club) {
                // Map pay_to => label (club overrides global)
                $clubPayTo = PayToOption::active()
                    ->where('club_id', $club->id)
                    ->get(['value', 'label'])
                    ->keyBy('value');

                $globalPayTo = PayToOption::active()
                    ->whereNull('club_id')
                    ->whereNotIn('value', $clubPayTo->keys())
                    ->get(['value', 'label'])
                    ->keyBy('value');

                $labelMap = $clubPayTo->concat($globalPayTo)->map->label->all();

                // Sum payments by pay_to via concept
                $paymentSums = Payment::query()
                    ->where('payments.club_id', $club->id)
                    ->leftJoin('payment_concepts', 'payment_concepts.id', '=', 'payments.payment_concept_id')
                    ->selectRaw('payment_concepts.pay_to as pay_to, COALESCE(SUM(payments.amount_paid),0) as total')
                    ->groupBy('payment_concepts.pay_to')
                    ->pluck('total', 'pay_to');

                // Sum expenses by pay_to
                $expenseSums = Expense::query()
                    ->where('club_id', $club->id)
                    ->selectRaw('pay_to, COALESCE(SUM(amount),0) as total')
                    ->groupBy('pay_to')
                    ->pluck('total', 'pay_to');

                // Union keys
                $accounts = $paymentSums->keys()->merge($expenseSums->keys())->unique();

                foreach ($accounts as $payTo) {
                    $entries = (float) ($paymentSums[$payTo] ?? 0);
                    $expenses = (float) ($expenseSums[$payTo] ?? 0);
                    $balance = $entries - $expenses;
                    $label = $labelMap[$payTo] ?? ($payTo ?? 'Unassigned');

                    $account = Account::firstOrCreate(
                        ['club_id' => $club->id, 'pay_to' => $payTo],
                        ['label' => $label, 'balance' => 0]
                    );
                    $account->label = $label;
                    $account->balance = $balance;
                    $account->save();
                }
            });

            $this->info("✓ Club {$club->id} ({$club->club_name}) done");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
