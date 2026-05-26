<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\FundraiserInvestmentReceipt;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RepairLedgerReceiptPaths extends Command
{
    protected $signature = 'finance:repair-ledger-receipt-paths
        {--write : Persist the repaired paths. Without this option the command only reports changes.}
        {--club_id= : Restrict repairs to one club id.}
        {--prefix=* : Storage disk prefix to scan. Defaults to the receipt/proof folders used by the ledger.}';

    protected $description = 'Repair income and expense ledger receipt paths by matching stored filenames in public storage.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $clubId = $this->option('club_id') ? (int) $this->option('club_id') : null;
        $prefixes = $this->option('prefix') ?: [
            'expense-receipts',
            'payments/checks',
            'reimbursement-receipts',
        ];

        $this->info(($write ? 'Repairing' : 'Dry run: scanning') . ' ledger receipt paths...');
        $this->line('Storage prefixes: ' . implode(', ', $prefixes));

        $index = $this->buildStorageIndex($prefixes);
        $this->line("Indexed {$index['total_files']} storage files; {$index['duplicates']} duplicate filenames skipped.");

        $stats = [
            'checked' => 0,
            'already_ok' => 0,
            'fixed' => 0,
            'missing' => 0,
            'duplicates' => 0,
            'empty' => 0,
        ];

        $this->repairPaymentPaths($index, $stats, $write, $clubId);
        $this->repairExpensePaths($index, $stats, $write, $clubId);
        $this->repairFundraiserInvestmentReceiptPaths($index, $stats, $write, $clubId);

        $this->newLine();
        $this->table(
            ['Checked', 'Already OK', $write ? 'Fixed' : 'Would fix', 'Missing', 'Duplicate name', 'Empty'],
            [[$stats['checked'], $stats['already_ok'], $stats['fixed'], $stats['missing'], $stats['duplicates'], $stats['empty']]]
        );

        if (!$write) {
            $this->warn('Dry run only. Re-run with --write to persist changes.');
        }

        return self::SUCCESS;
    }

    private function buildStorageIndex(array $prefixes): array
    {
        $pathsByName = [];
        $duplicates = [];
        $totalFiles = 0;

        foreach ($prefixes as $prefix) {
            foreach (Storage::disk('public')->allFiles(trim((string) $prefix, '/')) as $path) {
                $totalFiles++;
                $name = basename($path);

                if (isset($duplicates[$name])) {
                    continue;
                }

                if (isset($pathsByName[$name]) && $pathsByName[$name] !== $path) {
                    unset($pathsByName[$name]);
                    $duplicates[$name] = true;
                    continue;
                }

                $pathsByName[$name] = $path;
            }
        }

        return [
            'paths_by_name' => $pathsByName,
            'duplicates_by_name' => $duplicates,
            'total_files' => $totalFiles,
            'duplicates' => count($duplicates),
        ];
    }

    private function repairPaymentPaths(array $index, array &$stats, bool $write, ?int $clubId): void
    {
        Payment::query()
            ->when($clubId, fn (Builder $query) => $query->where('club_id', $clubId))
            ->whereNotNull('check_image_path')
            ->orderBy('id')
            ->chunkById(200, function ($payments) use ($index, &$stats, $write) {
                foreach ($payments as $payment) {
                    $this->repairModelPath($payment, 'check_image_path', $index, $stats, $write, 'Payment');
                }
            });
    }

    private function repairExpensePaths(array $index, array &$stats, bool $write, ?int $clubId): void
    {
        $fields = [
            'receipt_path',
            'reimbursement_receipt_path',
            'reimbursement_payment_proof_path',
        ];

        Expense::query()
            ->when($clubId, fn (Builder $query) => $query->where('club_id', $clubId))
            ->where(function (Builder $query) use ($fields) {
                foreach ($fields as $field) {
                    $query->orWhereNotNull($field);
                }
            })
            ->orderBy('id')
            ->chunkById(200, function ($expenses) use ($fields, $index, &$stats, $write) {
                foreach ($expenses as $expense) {
                    foreach ($fields as $field) {
                        $this->repairModelPath($expense, $field, $index, $stats, $write, 'Expense');
                    }
                }
            });
    }

    private function repairFundraiserInvestmentReceiptPaths(array $index, array &$stats, bool $write, ?int $clubId): void
    {
        FundraiserInvestmentReceipt::query()
            ->when($clubId, fn (Builder $query) => $query->whereHas('expense', fn (Builder $expense) => $expense->where('club_id', $clubId)))
            ->whereNotNull('path')
            ->orderBy('id')
            ->chunkById(200, function ($receipts) use ($index, &$stats, $write) {
                foreach ($receipts as $receipt) {
                    $this->repairModelPath($receipt, 'path', $index, $stats, $write, 'FundraiserInvestmentReceipt');
                }
            });
    }

    private function repairModelPath(Model $model, string $field, array $index, array &$stats, bool $write, string $label): void
    {
        $value = $model->{$field};

        if (!$value) {
            $stats['empty']++;
            return;
        }

        $stats['checked']++;
        $currentPath = $this->relativeStoragePath((string) $value);

        if ($currentPath && Storage::disk('public')->exists($currentPath)) {
            if ($currentPath === $value) {
                $stats['already_ok']++;
                return;
            }

            $this->recordRepair($model, $field, (string) $value, $currentPath, $stats, $write, $label);
            return;
        }

        $filename = $this->filenameFromPath((string) $value);
        if (!$filename) {
            $stats['missing']++;
            $this->warn("{$label} #{$model->getKey()} {$field}: no filename found in '{$value}'");
            return;
        }

        if (isset($index['duplicates_by_name'][$filename])) {
            $stats['duplicates']++;
            $this->warn("{$label} #{$model->getKey()} {$field}: '{$filename}' exists in multiple storage paths; skipped.");
            return;
        }

        $replacement = $index['paths_by_name'][$filename] ?? null;
        if (!$replacement) {
            $stats['missing']++;
            $this->warn("{$label} #{$model->getKey()} {$field}: '{$filename}' not found in scanned storage.");
            return;
        }

        $this->recordRepair($model, $field, (string) $value, $replacement, $stats, $write, $label);
    }

    private function recordRepair(Model $model, string $field, string $oldValue, string $newValue, array &$stats, bool $write, string $label): void
    {
        $stats['fixed']++;
        $this->line(($write ? 'FIX' : 'WOULD FIX') . " {$label} #{$model->getKey()} {$field}: {$oldValue} -> {$newValue}");

        if (!$write) {
            return;
        }

        $model->{$field} = $newValue;
        $model->save();
    }

    private function relativeStoragePath(string $value): ?string
    {
        $path = parse_url($value, PHP_URL_PATH) ?: $value;
        $path = ltrim(str_replace('\\', '/', rawurldecode($path)), '/');

        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                $path = Str::after($path, $prefix);
            }
        }

        return $path !== '' ? $path : null;
    }

    private function filenameFromPath(string $value): ?string
    {
        $path = $this->relativeStoragePath($value);
        $filename = $path ? basename($path) : null;

        return $filename && $filename !== '.' ? $filename : null;
    }
}
