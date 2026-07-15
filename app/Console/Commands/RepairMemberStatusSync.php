<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairMemberStatusSync extends Command
{
    protected $signature = 'members:repair-status-sync
        {--write : Persist repairs. Without this option the command only reports mismatches.}';

    protected $description = 'Detect and repair deleted-status drift between members and their detail records.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $definitions = [
            ['label' => 'adventurers', 'table' => 'members_adventurers', 'types' => ['adventurers']],
            ['label' => 'pathfinders', 'table' => 'members_pathfinders', 'types' => ['pathfinders', 'temp_pathfinder']],
            ['label' => 'master_guide', 'table' => 'member_master_guides', 'types' => ['master_guide']],
        ];

        $affected = [];
        foreach ($definitions as $definition) {
            $rows = DB::table('members as m')
                ->join($definition['table'] . ' as d', function ($join) {
                    $join->on('d.id', '=', 'm.id_data')
                        ->on('d.club_id', '=', 'm.club_id');
                })
                ->whereIn('m.type', $definition['types'])
                ->where(function ($query) {
                    $query->where(function ($statuses) {
                        $statuses->where('d.status', 'deleted')
                            ->where(fn ($unified) => $unified->whereNull('m.status')->orWhere('m.status', '!=', 'deleted'));
                    })->orWhere(function ($statuses) {
                        $statuses->where('m.status', 'deleted')
                            ->where(fn ($detail) => $detail->whereNull('d.status')->orWhere('d.status', '!=', 'deleted'));
                    });
                })
                ->select('m.id as member_id', 'm.id_data as detail_id', 'm.status as member_status', 'd.status as detail_status')
                ->orderBy('m.id')
                ->get();

            foreach ($rows as $row) {
                $affected[] = [
                    'type' => $definition['label'],
                    'member_id' => $row->member_id,
                    'detail_id' => $row->detail_id,
                    'member_status' => $row->member_status,
                    'detail_status' => $row->detail_status,
                ];
            }

            if ($write && $rows->isNotEmpty()) {
                DB::transaction(function () use ($rows, $definition) {
                    foreach ($rows as $row) {
                        // Deletion is intentionally dominant: never resurrect a record automatically.
                        DB::table('members')->where('id', $row->member_id)->update(['status' => 'deleted', 'updated_at' => now()]);
                        DB::table($definition['table'])->where('id', $row->detail_id)->update(['status' => 'deleted', 'updated_at' => now()]);
                    }
                });
            }
        }

        $this->table(['Type', 'Member ID', 'Detail ID', 'Member status', 'Detail status'], $affected);
        $this->info(sprintf('%s %d inconsistent member status pair(s).', $write ? 'Repaired' : 'Found', count($affected)));
        if (!$write) {
            $this->warn('Dry run only. Re-run with --write to mark both sides deleted.');
        }

        return self::SUCCESS;
    }
}
