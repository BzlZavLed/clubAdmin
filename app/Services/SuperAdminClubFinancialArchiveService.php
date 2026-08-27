<?php

namespace App\Services;

use App\Models\Club;
use App\Services\Finance\FinanceMovementReader;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SuperAdminClubFinancialArchiveService
{
    public function __construct(private readonly FinanceMovementReader $movementReader)
    {
    }

    public function build(Club $club): array
    {
        $workingDirectory = sys_get_temp_dir().'/club-financial-archive-'.Str::uuid();
        if (! mkdir($workingDirectory, 0700, true) && ! is_dir($workingDirectory)) {
            throw new RuntimeException('Could not create the financial archive directory.');
        }

        $filename = sprintf(
            '%s-%s-financial-archive-%s.zip',
            Str::slug($club->club_name) ?: 'club',
            $club->id,
            now()->format('Ymd-His')
        );
        $zipPath = tempnam(sys_get_temp_dir(), 'club-financial-archive-');
        if ($zipPath === false) {
            throw new RuntimeException('Could not reserve the financial archive file.');
        }
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the financial archive.');
        }

        $counts = [];

        try {
            $movements = $this->movementReader->movementsForClub($club);
            $counts['normalized_ledger'] = $this->addRowsCsv(
                $zip,
                $workingDirectory,
                'normalized-ledger.csv',
                $movements->map(fn (array $row) => $this->flattenMovement($row))->all()
            );

            foreach ($this->rawFinancialQueries($club) as $name => $query) {
                $counts[$name] = $this->addQueryCsv($zip, $workingDirectory, "raw/{$name}.csv", $query);
            }

            $summary = $this->movementReader->summaryForClub($club);
            $manifest = [
                'archive_version' => 1,
                'generated_at' => now()->toIso8601String(),
                'club' => [
                    'id' => $club->id,
                    'name' => $club->club_name,
                    'type' => $club->club_type,
                    'church' => $club->church_name,
                    'status' => $club->status,
                ],
                'record_counts' => $counts,
                'financial_summary' => $summary,
            ];

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('README.txt', implode("\n", [
                'PERMANENT CLUB DELETION - FINANCIAL ARCHIVE',
                '',
                "Club: {$club->club_name} (#{$club->id})",
                'Generated: '.now()->toIso8601String(),
                '',
                'normalized-ledger.csv contains the human-readable accounting ledger.',
                'The raw/ directory contains the underlying financial database records,',
                'including payments, receipts, expenses, accounts, transfers, fundraisers,',
                'parent submissions, event settlements, concepts, allocations, and overrides.',
                '',
                'Keep this ZIP in a secure location. Club cleanup and deletion are irreversible.',
            ]));
        } finally {
            $zip->close();
            foreach (glob($workingDirectory.'/*') ?: [] as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
            @rmdir($workingDirectory);
        }

        return ['path' => $zipPath, 'filename' => $filename, 'counts' => $counts];
    }

    public function fingerprint(Club $club): string
    {
        $context = hash_init('sha256');

        foreach ($this->rawFinancialQueries($club) as $name => $query) {
            hash_update($context, $name."\n");
            $query->chunkById(500, function ($rows) use ($context): void {
                foreach ($rows as $row) {
                    hash_update($context, json_encode((array) $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                }
            });
        }

        return hash_final($context);
    }

    private function rawFinancialQueries(Club $club): array
    {
        $clubId = (int) $club->id;
        $queries = [];
        $directTables = [
            'accounts',
            'payments',
            'payment_receipts',
            'expenses',
            'treasury_movements',
            'payment_concepts',
            'payment_concept_scopes',
            'parent_payment_submissions',
            'finance_movement_concept_overrides',
            'finance_reimbursement_payees',
            'fundraiser_events',
            'fundraiser_sales',
            'event_club_settlements',
        ];

        foreach ($directTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'club_id')) {
                $queries[$table] = DB::table($table)->where('club_id', $clubId)->orderBy('id');
            }
        }

        if (Schema::hasTable('fundraiser_partner_transfers')) {
            $queries['fundraiser_partner_transfers'] = DB::table('fundraiser_partner_transfers')
                ->where('from_club_id', $clubId)->orWhere('to_club_id', $clubId)->orderBy('id');
        }
        if (Schema::hasTable('fundraiser_event_partners')) {
            $queries['fundraiser_event_partners'] = DB::table('fundraiser_event_partners')
                ->where('partner_club_id', $clubId)
                ->orWhereIn('fundraiser_event_id', DB::table('fundraiser_events')->select('id')->where('club_id', $clubId))
                ->orderBy('id');
        }
        if (Schema::hasTable('fundraiser_products')) {
            $queries['fundraiser_products'] = DB::table('fundraiser_products')
                ->whereIn('fundraiser_event_id', DB::table('fundraiser_events')->select('id')->where('club_id', $clubId))
                ->orderBy('id');
        }
        if (Schema::hasTable('fundraiser_investment_receipts')) {
            $queries['fundraiser_investment_receipts'] = DB::table('fundraiser_investment_receipts')
                ->whereIn('fundraiser_event_id', DB::table('fundraiser_events')->select('id')->where('club_id', $clubId))
                ->orderBy('id');
        }
        if (Schema::hasTable('fundraiser_sale_items')) {
            $queries['fundraiser_sale_items'] = DB::table('fundraiser_sale_items')
                ->whereIn('fundraiser_sale_id', DB::table('fundraiser_sales')->select('id')->where('club_id', $clubId))
                ->orderBy('id');
        }
        if (Schema::hasTable('payment_allocations')) {
            $queries['payment_allocations'] = DB::table('payment_allocations')
                ->whereIn('payment_id', DB::table('payments')->select('id')->where('club_id', $clubId))
                ->orderBy('id');
        }
        if (Schema::hasTable('event_budget_items')) {
            $queries['event_budget_items'] = DB::table('event_budget_items')
                ->whereIn('event_id', DB::table('events')->select('id')->where('club_id', $clubId))
                ->orderBy('id');
        }

        return $queries;
    }

    private function addQueryCsv(ZipArchive $zip, string $directory, string $archiveName, Builder $query): int
    {
        $path = $directory.'/'.str_replace('/', '-', $archiveName);
        $handle = fopen($path, 'wb');
        $count = 0;
        $headersWritten = false;

        $query->chunkById(500, function ($rows) use ($handle, &$count, &$headersWritten): void {
            foreach ($rows as $row) {
                $values = (array) $row;
                if (! $headersWritten) {
                    fputcsv($handle, array_keys($values));
                    $headersWritten = true;
                }
                fputcsv($handle, array_map(fn ($value) => $this->csvValue($value), array_values($values)));
                $count++;
            }
        });

        fclose($handle);
        $zip->addFile($path, $archiveName);

        return $count;
    }

    private function addRowsCsv(ZipArchive $zip, string $directory, string $archiveName, array $rows): int
    {
        $path = $directory.'/'.str_replace('/', '-', $archiveName);
        $handle = fopen($path, 'wb');
        $headers = collect($rows)->flatMap(fn (array $row) => array_keys($row))->unique()->values()->all();

        if ($headers !== []) {
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($header) => $this->csvValue($row[$header] ?? null), $headers));
            }
        }

        fclose($handle);
        $zip->addFile($path, $archiveName);

        return count($rows);
    }

    private function flattenMovement(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $row[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $row;
    }

    private function csvValue(mixed $value): mixed
    {
        return is_array($value) || is_object($value)
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $value;
    }
}
