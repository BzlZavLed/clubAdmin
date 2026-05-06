<?php

namespace App\Support;

use App\Models\BankInfo;

class BankInfoFormatter
{
    public static function payload(?BankInfo $bankInfo): ?array
    {
        if (!$bankInfo) {
            return null;
        }

        return [
            'id' => (int) $bankInfo->id,
            'pay_to' => $bankInfo->pay_to,
            'label' => $bankInfo->label,
            'bank_name' => $bankInfo->bank_name,
            'account_holder' => $bankInfo->account_holder,
            'account_type' => $bankInfo->account_type,
            'account_number' => $bankInfo->account_number,
            'routing_number' => $bankInfo->routing_number,
            'zelle_email' => $bankInfo->zelle_email,
            'zelle_phone' => $bankInfo->zelle_phone,
            'deposit_instructions' => $bankInfo->deposit_instructions,
            'is_active' => (bool) $bankInfo->is_active,
            'accepts_parent_deposits' => (bool) $bankInfo->accepts_parent_deposits,
            'accepts_event_deposits' => (bool) $bankInfo->accepts_event_deposits,
            'requires_receipt_upload' => (bool) $bankInfo->requires_receipt_upload,
        ];
    }
}
