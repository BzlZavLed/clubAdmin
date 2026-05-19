<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DevResetFinanceLedger extends Command
{
    protected $signature = 'finance:dev-reset-ledger {--force : Run without confirmation}';

    protected $description = 'DEV ONLY: hard-delete finance ledger rows and reset account balances.';

    private array $clearedTables = [];

    public function handle(): int
    {
        if (!app()->environment(['local', 'development', 'testing'])) {
            $this->error('Refusing to run outside local/development/testing.');

            return self::FAILURE;
        }

        $database = DB::connection()->getDatabaseName();
        if (!$this->option('force') && !$this->confirm("This will delete finance ledger rows from database [{$database}]. Continue?", false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $this->info('Resetting finance ledger rows...');

        DB::transaction(function () {
            $this->clearLinkedFinanceColumns();
            $this->deleteRowsInOrder();
            $this->resetAccountBalances();
        });

        $this->resetSequences();

        $this->info('Finance ledger reset complete.');
        $this->line('Kept clubs, users, members, payment concepts, accounts, bank setup, and uploaded files.');

        return self::SUCCESS;
    }

    private function clearLinkedFinanceColumns(): void
    {
        $this->updateNullableColumns('event_budget_items', [
            'expense_id',
            'reimbursement_expense_id',
            'expense_date',
            'receipt_path',
        ]);

        $this->updateNullableColumns('rep_assistance_adv_merits', ['payment_id']);
        $this->updateNullableColumns('payments', [
            'reversed_payment_id',
            'settles_expense_id',
            'related_canceled_movement_id',
            'canceling_id',
        ]);
        $this->updateNullableColumns('expenses', [
            'settles_expense_id',
            'reimbursement_origin_expense_id',
            'reversed_expense_id',
            'related_canceled_movement_id',
            'canceling_id',
        ]);
    }

    private function deleteRowsInOrder(): void
    {
        $this->deleteFinanceDocumentValidations();

        foreach ([
            'payment_receipts',
            'payment_allocations',
            'parent_payment_submissions',
            'fundraiser_sale_items',
            'fundraiser_partner_transfers',
            'fundraiser_sales',
            'fundraiser_products',
            'fundraiser_event_partners',
            'fundraiser_events',
            'treasury_movements',
            'event_club_settlements',
            'payments',
            'expenses',
            'finance_reimbursement_payees',
        ] as $table) {
            $this->deleteFrom($table);
        }
    }

    private function deleteFinanceDocumentValidations(): void
    {
        if (!Schema::hasTable('document_validations')) {
            return;
        }

        $count = DB::table('document_validations')
            ->whereIn('document_type', [
                'event_club_settlement_receipt',
                'event_financial_report',
                'finance_engine_accounting',
                'finance_engine_movements',
                'payment_cancellation_receipt',
                'payment_receipt',
            ])
            ->delete();

        $this->line("document_validations: {$count}");
    }

    private function updateNullableColumns(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));

        if ($existingColumns === []) {
            return;
        }

        DB::table($table)->update(array_fill_keys($existingColumns, null));
    }

    private function deleteFrom(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $count = DB::table($table)->delete();
        $this->clearedTables[] = $table;
        $this->line("{$table}: {$count}");
    }

    private function resetAccountBalances(): void
    {
        if (!Schema::hasTable('accounts') || !Schema::hasColumn('accounts', 'balance')) {
            return;
        }

        $count = DB::table('accounts')->update(['balance' => 0]);
        $this->line("accounts balance reset: {$count}");
    }

    private function resetSequences(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (array_unique($this->clearedTables) as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if ($driver === 'pgsql') {
                DB::statement("ALTER SEQUENCE IF EXISTS {$table}_id_seq RESTART WITH 1");
            } elseif ($driver === 'mysql') {
                DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
            } elseif ($driver === 'sqlite' && Schema::hasTable('sqlite_sequence')) {
                DB::table('sqlite_sequence')->where('name', $table)->delete();
            }
        }
    }
}
