<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PayToOption;
use App\Models\Club;
use Illuminate\Support\Str;

class SeedPayToOptions extends Command
{
    protected $signature = 'payto:seed {--club_id=}';

    protected $description = 'Backfill/seed default pay_to options for all clubs (or a single club).';

    protected array $defaults = [
        'church_budget',
        'club_budget',
        'conference',
        'reimbursement_to', // canonical key
    ];

    public function handle(): int
    {
        $clubId = $this->option('club_id');
        $clubs = $clubId ? Club::where('id', $clubId)->get() : Club::all();

        if ($clubs->isEmpty()) {
            $this->warn('No clubs found.');
            return self::SUCCESS;
        }

        foreach ($clubs as $club) {
            foreach ($this->defaults as $value) {
                PayToOption::firstOrCreate(
                    ['club_id' => $club->id, 'value' => $value],
                    [
                        'label' => Str::title(str_replace('_', ' ', $value)),
                        'status' => 'active',
                        'created_by' => null,
                    ]
                );
            }
        }

        $this->info('Pay-to options seeded for '.$clubs->count().' club(s).');
        return self::SUCCESS;
    }
}
