<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ScopeType;
use App\Models\PayToOption;

class PaymentCatalogsSeeder extends Seeder
{
    public function run(): void
    {
        $scopeTypeOptions = [
            ['value' => 'club_wide',  'label' => 'Club wide'],
            ['value' => 'class',      'label' => 'Specific class'],
            ['value' => 'member',     'label' => 'Specific member'],
            ['value' => 'staff_wide', 'label' => 'Staff wide'],
            ['value' => 'staff',      'label' => 'Specific staff'],
        ];

        $payToOptions = [
            ['value' => 'church_budget',   'label' => 'Church budget'],
            ['value' => 'club_budget',     'label' => 'Club budget'],
            ['value' => 'conference',      'label' => 'Conference'],
            ['value' => 'reimbursement_to','label' => 'Reimbursement to…'],
        ];

        foreach ($scopeTypeOptions as $opt) {
            ScopeType::updateOrCreate(
                ['club_id' => 2, 'value' => $opt['value']],
                ['label' => $opt['label'], 'status' => 'active']
            );
        }

        foreach ($payToOptions as $opt) {
            PayToOption::updateOrCreate(
                ['club_id' => 2, 'value' => $opt['value']],
                ['label' => $opt['label'], 'status' => 'active']
            );
        }
    }
}

