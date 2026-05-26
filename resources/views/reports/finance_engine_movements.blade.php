<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Libro contable financiero</title>
    <style>
        @page { margin: 20mm 12mm 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 10px; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 12px; margin: 14px 0 6px; }
        h3 { font-size: 11px; margin: 12px 0 5px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 5px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-weight: 700; }
        .ledger-table { table-layout: fixed; font-size: 8px; }
        .ledger-table thead { display: table-row-group; }
        .ledger-table th, .ledger-table td { padding: 3px; overflow-wrap: break-word; word-wrap: break-word; }
        .ledger-table .date-column { width: 7%; white-space: nowrap; }
        .ledger-table .type-column { width: 9%; }
        .ledger-table .account-column { width: 16%; }
        .ledger-table .location-column { width: 9%; }
        .ledger-table .concept-column { width: 18%; }
        .ledger-table .counterparty-column { width: 11%; }
        .ledger-table .document-column { width: 13%; }
        .ledger-table .status-column { width: 7%; }
        .ledger-table .amount-column { width: 5%; }
        .ledger-table .balance-column { width: 5%; }
        .section-title { page-break-after: avoid; }
        .header { display: table; width: 100%; margin-bottom: 12px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; }
        .header-right { text-align: right; width: 180px; }
        .logo { max-height: 52px; max-width: 80px; }
        .muted { color: #6b7280; }
        .summary { display: table; width: 100%; margin: 10px 0 14px; }
        .summary-card { display: table-cell; border: 1px solid #d1d5db; padding: 8px; width: 33.3%; }
        .amount-in { color: #047857; font-weight: 700; }
        .amount-out { color: #be123c; font-weight: 700; }
        .amount-transfer { color: #0369a1; font-weight: 700; }
        .amount-cell { white-space: nowrap; }
        .balance-cell { white-space: pre-line; }
        .annex-page { page-break-before: always; }
        .annex-page-current { page-break-before: auto; }
        .annex-title { font-size: 15px; margin: 0 0 8px; }
        .annex-subtitle { color: #4b5563; font-size: 9px; margin-bottom: 10px; }
        .annex-meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .annex-meta td { width: 20%; border: 1px solid #d1d5db; padding: 6px; }
        .annex-label { color: #6b7280; font-size: 8px; text-transform: uppercase; }
        .annex-value { font-size: 10px; font-weight: 700; margin-top: 2px; word-break: break-word; }
        .annex-preview { border: 1px solid #d1d5db; padding: 8px; text-align: center; background: #ffffff; }
        .annex-image { max-width: 100%; max-height: 410px; object-fit: contain; }
        .annex-link-box { border: 1px dashed #94a3b8; background: #f8fafc; padding: 18px; text-align: center; }
        .annex-link-title { font-size: 12px; font-weight: 700; margin-bottom: 8px; }
        .annex-compact-section { page-break-before: always; }
        .annex-compact-card { border: 1px solid #d1d5db; background: #ffffff; padding: 8px; margin-bottom: 8px; page-break-inside: avoid; }
        .annex-compact-group { font-size: 12px; font-weight: 700; margin: 12px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; }
        .annex-compact-title { font-size: 11px; font-weight: 700; margin-bottom: 4px; }
        .annex-compact-meta { font-size: 8px; color: #6b7280; margin-bottom: 4px; }
        .annex-compact-link { font-size: 8px; word-break: break-all; }
        .inline-receipt { border: 1px solid #d1d5db; background: #fff; }
        .inline-receipt-top { border-bottom: 1px solid #d1d5db; padding: 14px 16px; }
        .inline-receipt-header { width: 100%; border-collapse: collapse; }
        .inline-receipt-header td { border: 0; padding: 0; vertical-align: middle; }
        .inline-receipt-logo { width: 48px; height: 48px; object-fit: contain; border: 1px solid #d1d5db; padding: 3px; }
        .inline-receipt-club { font-size: 14px; font-weight: 700; }
        .inline-receipt-subtitle { color: #6b7280; font-size: 9px; margin-top: 2px; }
        .inline-receipt-title { text-align: right; font-size: 18px; font-weight: 700; text-transform: uppercase; }
        .inline-receipt-number { display: inline-block; margin-top: 5px; padding: 4px 8px; color: #1d4ed8; border: 1px solid #bfdbfe; background: #eff6ff; font-size: 10px; font-weight: 700; }
        .inline-receipt-body { padding: 14px 16px; }
        .inline-receipt-grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .inline-receipt-grid td { width: 33.333%; border: 1px solid #e5e7eb; padding: 8px; }
        .inline-receipt-detail { width: 100%; border-collapse: collapse; }
        .inline-receipt-detail td { border: 1px solid #e5e7eb; padding: 8px; vertical-align: top; }
        .inline-receipt-total { margin-top: 12px; border: 1px solid #bfdbfe; background: #eff6ff; padding: 10px 12px; text-align: right; }
        .inline-receipt-total-value { color: #1d4ed8; font-size: 22px; font-weight: 700; }
        a { color: #0369a1; text-decoration: underline; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; border-top: 1px solid #d1d5db; padding-top: 4px; font-size: 8px; color: #6b7280; }
        .footer img { height: 34px; float: right; }
    </style>
</head>
<body>
@php
    $money = fn ($value) => '$' . number_format((float) ($value ?? 0), 2);
    $signedMoney = fn ($value) => ((float) ($value ?? 0)) < 0
        ? '-$' . number_format(abs((float) $value), 2)
        : '$' . number_format((float) ($value ?? 0), 2);
    $annexOnly = $annexOnly ?? false;
    $ledgerOnly = $ledgerOnly ?? false;
    $includeIncomeReceiptAnnexes = $includeIncomeReceiptAnnexes ?? false;
    $movements = collect($report['movements'] ?? []);
    $summary = $report['summary'] ?? [];
    $receiptAnnexes = collect($receiptAnnexes ?? [])->values()->map(function (array $annex, int $index) {
        $annex['anchor'] = $annex['anchor'] ?? 'ledger-annex-' . ($index + 1);

        return $annex;
    });
    $appendixGroupLabels = [
        'reimbursement_receipt' => 'Reembolsos',
        'reimbursement_payment_proof' => 'Pagos de reembolso',
        'expense_receipt' => 'Gastos',
        'income_receipt' => 'Ingresos',
        'payment_receipt' => 'Ingresos',
        'check_image' => 'Cheques',
        'fundraiser_investment_receipt' => 'Inversiones de fundraiser',
        'treasury_proof' => 'Transferencias',
        'proof' => 'Otros comprobantes',
        'other' => 'Otros comprobantes',
        'income' => 'Ingresos',
        'expense' => 'Gastos',
        'reimbursement' => 'Reembolsos',
        'transfer' => 'Transferencias',
    ];
    $appendixGroupOrder = [
        'reimbursement_receipt' => 10,
        'reimbursement_payment_proof' => 20,
        'expense_receipt' => 30,
        'income_receipt' => 40,
        'payment_receipt' => 40,
        'check_image' => 50,
        'fundraiser_investment_receipt' => 60,
        'treasury_proof' => 70,
        'proof' => 90,
        'other' => 99,
        'reimbursement' => 10,
        'expense' => 30,
        'income' => 40,
        'transfer' => 70,
    ];
    $naturalReferenceSortKey = function (?string $value) {
        $value = strtoupper((string) ($value ?? ''));

        return preg_replace_callback('/\d+/', fn ($matches) => str_pad($matches[0], 12, '0', STR_PAD_LEFT), $value);
    };
    $movementReferenceText = function (array $movement) {
        if (!empty($movement['receipt']['number'])) {
            return $movement['receipt']['number'];
        }
        if (!empty($movement['reference'])) {
            return $movement['reference'];
        }

        $movementId = (string) ($movement['movement_id'] ?? '');
        [$type, $id] = array_pad(explode(':', $movementId, 2), 2, null);

        return match ($type) {
            'payment' => 'RCPT-' . $id,
            'expense' => (
                str_contains(strtolower((string) ($movement['concept'] ?? '')), 'reimburs')
                || str_contains(strtolower((string) ($movement['concept'] ?? '')), 'reembolso')
            ) ? 'REIMB-' . $id : 'EXP-' . $id,
            'treasury' => 'TREAS-' . $id,
            default => strtoupper($movementId ?: 'MOV'),
        };
    };
    $receiptAnnexGroups = $receiptAnnexes
        ->groupBy(fn (array $annex) => $annex['document_type'] ?? 'other')
        ->sortBy(fn ($items, $type) => $appendixGroupOrder[$type] ?? 99)
        ->map(fn ($items) => $items
            ->sortBy(fn (array $annex) => $naturalReferenceSortKey((string) ($annex['reference'] ?? $annex['title'] ?? $annex['filename'] ?? '')))
            ->values()
        );
    $annexUrlAnchors = $receiptAnnexes
        ->filter(fn (array $annex) => !empty($annex['url']))
        ->mapWithKeys(fn (array $annex) => [$annex['url'] => '#' . $annex['anchor']])
        ->all();
    $annexReferenceAnchors = $receiptAnnexes
        ->filter(fn (array $annex) => !empty($annex['reference']))
        ->mapWithKeys(fn (array $annex) => [$annex['reference'] => '#' . $annex['anchor']])
        ->all();
    $accountLabels = collect($summary['accounts'] ?? [])
        ->mapWithKeys(fn ($row) => [$row['account'] ?? '' => $row['label'] ?? $row['account'] ?? ''])
        ->filter(fn ($label, $account) => $account !== '')
        ->all();
    $locationLabels = [
        'cash' => 'Efectivo',
        'bank' => 'Banco',
        'external' => 'Externo',
        'internal' => 'Interno',
        'pending' => 'Pendiente',
        'staff_custody' => 'Custodia staff',
    ];
    $proofLabels = [
        'check_image' => 'Cheque',
        'expense_receipt' => 'Comprobante de gasto',
        'reimbursement_payment_proof' => 'Comprobante de pago de reembolso',
        'reimbursement_receipt' => 'Comprobante de reembolso',
        'fundraiser_investment_receipt' => 'Comprobante de inversion',
        'treasury_proof' => 'Comprobante de transferencia',
    ];
    $paymentTypeLabels = [
        'cash' => 'Efectivo',
        'zelle' => 'Zelle',
        'check' => 'Cheque',
        'transfer' => 'Transferencia',
        'internal' => 'Interno',
        'initial' => 'Inicial',
    ];
    $isTransfer = fn (array $movement) => ($movement['direction'] ?? null) === 'transfer' || ($movement['domain'] ?? null) === 'transfer';
    $locationLabel = fn ($value) => $value ? ($locationLabels[$value] ?? $value) : '-';
    $paymentTypeLabel = fn ($value) => $value ? ($paymentTypeLabels[$value] ?? $value) : '-';
    $movementAccountText = function (array $movement) use ($isTransfer, $locationLabel) {
        if ($isTransfer($movement)) {
            $from = $movement['from_account_label'] ?? $movement['from_account'] ?? $movement['account_label'] ?? $movement['account'] ?? '-';
            $to = $movement['to_account_label'] ?? $movement['to_account'] ?? $movement['account_label'] ?? $movement['account'] ?? '-';
            $fromLocation = $locationLabel($movement['from_location'] ?? null);
            $toLocation = $locationLabel($movement['to_location'] ?? null);

            return "{$from} ({$fromLocation}) -> {$to} ({$toLocation})";
        }

        return $movement['account_label'] ?? $movement['account'] ?? $movement['from_account'] ?? '-';
    };
    $movementLocationText = function (array $movement) use ($isTransfer, $locationLabel) {
        if ($isTransfer($movement)) {
            return $locationLabel($movement['from_location'] ?? null) . ' -> ' . $locationLabel($movement['to_location'] ?? null);
        }

        return $locationLabel($movement['location'] ?? $movement['from_location'] ?? null);
    };
    $isCorrectionMovement = function (array $movement) {
        $status = $movement['status'] ?? null;
        $kind = (string) ($movement['kind'] ?? '');

        return $status === 'cancellation'
            || str_contains($kind, 'reversal')
            || !empty($movement['canceling_id'])
            || !empty($movement['canceling_movement_key']);
    };
    $isReimbursementSettlementIncome = function (array $movement) {
        if (($movement['domain'] ?? null) !== 'income') {
            return false;
        }

        $account = $movement['account'] ?? $movement['to_account'] ?? null;

        return $account === 'reimbursement_to'
            && (!empty($movement['settles_expense_id']) || !empty($movement['reimbursement_group']));
    };
    $isInternalReimbursementBalanceExpense = function (array $movement) {
        if (($movement['domain'] ?? null) !== 'expense') {
            return false;
        }

        $account = $movement['account'] ?? $movement['from_account'] ?? null;
        $group = $movement['reimbursement_group'] ?? [];
        $hasOriginExpense = !empty($movement['reimbursement_origin_expense_id'])
            || (is_array($group) && !empty($group['origin_expense_id']));

        return $account === 'reimbursement_to'
            && empty($movement['settles_expense_id'])
            && $hasOriginExpense;
    };
    $proofReferenceText = function (array $movement, array $proof) use ($movementReferenceText) {
        $movementId = (string) ($movement['movement_id'] ?? '');
        [, $id] = array_pad(explode(':', $movementId, 2), 2, null);

        return match ($proof['type'] ?? null) {
            'check_image' => 'PAY-' . $id,
            'expense_receipt' => 'EXP-' . $id,
            'reimbursement_payment_proof' => 'REIMB-PAY-' . $id,
            'reimbursement_receipt' => 'REIMB-' . $id,
            'fundraiser_investment_receipt' => 'INV-' . $id,
            'treasury_proof' => 'TREAS-' . $id,
            default => $movementReferenceText($movement),
        };
    };
    $documentLinks = function (array $movement) use ($proofLabels, $annexUrlAnchors, $annexReferenceAnchors, $isCorrectionMovement, $isReimbursementSettlementIncome, $isInternalReimbursementBalanceExpense, $proofReferenceText) {
        if ($isCorrectionMovement($movement) || $isReimbursementSettlementIncome($movement) || $isInternalReimbursementBalanceExpense($movement)) {
            return [];
        }

        $links = [];
        $receipt = $movement['receipt'] ?? null;

        if (is_array($receipt) && (!empty($receipt['number']) || !empty($receipt['url']))) {
            $number = $receipt['number'] ?? null;
            $url = $receipt['url'] ?? null;
            $links[] = [
                'label' => $number ?? 'Recibo',
                'url' => null,
            ];
        }

        $proofs = [];
        if (!empty($movement['proofs']) && is_array($movement['proofs'])) {
            $proofs = $movement['proofs'];
        } elseif (!empty($movement['proof']) && is_array($movement['proof'])) {
            $proofs[] = $movement['proof'];
        }

        $proofTypeTotals = [];
        foreach ($proofs as $proof) {
            if (is_array($proof)) {
                $type = $proof['type'] ?? 'proof';
                $proofTypeTotals[$type] = ($proofTypeTotals[$type] ?? 0) + 1;
            }
        }

        $proofTypeCounts = [];
        foreach ($proofs as $proof) {
            if (!is_array($proof) || (empty($proof['name']) && empty($proof['url']) && empty($proof['type']))) {
                continue;
            }

            $type = $proof['type'] ?? null;
            $url = $proof['url'] ?? null;
            $typeKey = $type ?? 'proof';
            $proofTypeCounts[$typeKey] = ($proofTypeCounts[$typeKey] ?? 0) + 1;
            $reference = $proofReferenceText($movement, $proof);
            if (($proofTypeTotals[$typeKey] ?? 0) > 1) {
                $reference .= '-' . $proofTypeCounts[$typeKey];
            }

            $links[] = [
                'label' => ($proofLabels[$type] ?? 'Comprobante') . ' ' . $reference,
                'url' => null,
            ];
        }

        return $links;
    };
    $movementHasDocuments = function (array $movement) use ($isCorrectionMovement, $isReimbursementSettlementIncome, $isInternalReimbursementBalanceExpense, $annexUrlAnchors, $annexReferenceAnchors, $movementReferenceText) {
        if ($isCorrectionMovement($movement) || $isReimbursementSettlementIncome($movement) || $isInternalReimbursementBalanceExpense($movement)) {
            return false;
        }

        $receipt = $movement['receipt'] ?? null;
        if (is_array($receipt) && (
            (!empty($receipt['number']) && isset($annexReferenceAnchors[$receipt['number']]))
            || (!empty($receipt['url']) && isset($annexUrlAnchors[$receipt['url']]))
        )) {
            return true;
        }

        if (!empty($movement['proofs']) && is_array($movement['proofs'])) {
            foreach ($movement['proofs'] as $proof) {
                if (is_array($proof) && (
                    (!empty($proof['url']) && isset($annexUrlAnchors[$proof['url']]))
                    || isset($annexReferenceAnchors[$movementReferenceText($movement)])
                )) {
                    return true;
                }
            }
        }

        $proof = $movement['proof'] ?? null;
        return is_array($proof) && (
            (!empty($proof['url']) && isset($annexUrlAnchors[$proof['url']]))
            || isset($annexReferenceAnchors[$movementReferenceText($movement)])
        );
    };
    $movementGroupType = function (array $movement) {
        $domain = $movement['domain'] ?? 'other';
        $kind = strtolower((string) ($movement['kind'] ?? ''));
        $concept = strtolower((string) ($movement['concept'] ?? ''));

        if ($domain === 'expense' && (str_contains($kind, 'reimbursement') || str_contains($concept, 'reembolso') || str_contains($concept, 'reimburs'))) {
            return 'reimbursement';
        }

        return in_array($domain, ['income', 'expense', 'transfer'], true) ? $domain : 'other';
    };
    $movementsWithoutReceiptsGroups = $movements
        ->filter(fn (array $movement) => !$isCorrectionMovement($movement)
            && !$isReimbursementSettlementIncome($movement)
            && !$isInternalReimbursementBalanceExpense($movement)
            && ($includeIncomeReceiptAnnexes || ($movement['domain'] ?? null) !== 'income')
            && !$movementHasDocuments($movement))
        ->groupBy(fn (array $movement) => $movementGroupType($movement))
        ->sortBy(fn ($items, $type) => $appendixGroupOrder[$type] ?? 99)
        ->map(fn ($items) => $items
            ->sortBy(fn (array $movement) => $naturalReferenceSortKey($movementReferenceText($movement)))
            ->values()
        );
    $amountClass = function (array $movement) use ($isTransfer) {
        if ($isTransfer($movement)) {
            return 'amount-transfer';
        }

        return ($movement['direction'] ?? null) === 'out' ? 'amount-out' : 'amount-in';
    };
    $movementBalanceText = function (array $movement, ?string $account = null) use ($money, $isTransfer) {
        $balance = $movement['balance_after'] ?? null;
        if (!$balance || !is_array($balance)) {
            return '-';
        }

        if ($isTransfer($movement)) {
            $from = $balance['from'] ?? null;
            $to = $balance['to'] ?? null;

            if (!$from && !$to) {
                return '-';
            }

            if ($account) {
                if (($from['account'] ?? null) === $account) {
                    return $money($from['account_balance'] ?? 0);
                }
                if (($to['account'] ?? null) === $account) {
                    return $money($to['account_balance'] ?? 0);
                }

                return '-';
            }

            if (($from['account'] ?? null) && ($from['account'] ?? null) === ($to['account'] ?? null)) {
                return $money($from['account_balance'] ?? 0);
            }

            return 'Origen: ' . $money($from['account_balance'] ?? 0)
                . "\n" . 'Destino: ' . $money($to['account_balance'] ?? 0);
        }

        return array_key_exists('account_balance', $balance)
            ? $money($balance['account_balance'])
            : '-';
    };
    $isAllAccountsReport = empty($report['filters']['account'] ?? null);
    $movementSection = [
        'account' => $report['filters']['account'] ?? null,
        'label' => $isAllAccountsReport ? 'Todos los movimientos' : null,
        'movements' => $movements->all(),
    ];
@endphp

<div class="footer">
    @if(!empty($qrCodeDataUri))
        <img src="{{ $qrCodeDataUri }}" alt="QR">
    @endif
    Documento generado desde el motor financiero. Validar con el codigo QR.
</div>

<div class="header">
    <div class="header-left">
        <h1>{{ $annexOnly ? 'Anexos de recibos' : 'Libro contable financiero' }}</h1>
        <div class="muted">{{ $club->club_name ?? 'Club' }}</div>
        <div class="muted">Generado: {{ optional($generatedAt)->format('Y-m-d H:i') }}</div>
    </div>
    <div class="header-right">
        @if(!empty($clubLogoDataUri))
            <img class="logo" src="{{ $clubLogoDataUri }}" alt="Logo">
        @endif
    </div>
</div>

@if(!$annexOnly)
<div class="summary">
    <div class="summary-card">
        <div class="muted">Efectivo</div>
        <strong>{{ $money($summary['cash_balance'] ?? 0) }}</strong>
    </div>
    <div class="summary-card">
        <div class="muted">Banco</div>
        <strong>{{ $money($summary['bank_balance'] ?? 0) }}</strong>
    </div>
    <div class="summary-card">
        <div class="muted">Total disponible</div>
        <strong>{{ $money($summary['total_available'] ?? (($summary['cash_balance'] ?? 0) + ($summary['bank_balance'] ?? 0))) }}</strong>
    </div>
</div>

<h2>Movimientos</h2>
@if($isAllAccountsReport)
    <h3 class="section-title">{{ $movementSection['label'] }}</h3>
@endif
@if(empty($movementSection['movements']))
    <table>
        <tbody>
            <tr>
                <td style="text-align:center;">No hay movimientos para los filtros seleccionados.</td>
            </tr>
        </tbody>
    </table>
@else
    <table class="ledger-table">
        <thead>
            <tr>
                <th class="date-column">Fecha</th>
                <th class="type-column">Tipo</th>
                <th class="account-column">Cuenta</th>
                <th class="location-column">Ubicacion</th>
                <th class="concept-column">Concepto</th>
                <th class="counterparty-column">Contraparte</th>
                <th class="document-column">Recibo / comprobante</th>
                <th class="status-column">Estado</th>
                <th class="amount-column" style="text-align:right;">Monto</th>
                <th class="balance-column" style="text-align:right;">Balance cuenta</th>
            </tr>
        </thead>
        <tbody>
            @foreach($movementSection['movements'] as $movement)
                @php
                    $direction = $movement['direction'] ?? null;
                    $documents = $documentLinks($movement);
                @endphp
                <tr>
                    <td class="date-column">{{ $movement['date'] ?? '-' }}</td>
                    <td class="type-column">{{ $movement['domain'] ?? '-' }}<br><span class="muted">{{ $movement['kind'] ?? '' }}</span></td>
                    <td class="account-column">{{ $movementAccountText($movement) }}</td>
                    <td class="location-column">{{ $movementLocationText($movement) }}</td>
                    <td class="concept-column">{{ $movement['concept'] ?? '-' }}</td>
                    <td class="counterparty-column">{{ $movement['counterparty'] ?? $movement['created_by'] ?? '-' }}</td>
                    <td class="document-column">
                        @if(empty($documents))
                            -
                        @else
                            @foreach($documents as $document)
                                @if(!empty($document['url']))
                                    <a href="{{ $document['url'] }}">{{ $document['label'] }}</a>
                                @else
                                    {{ $document['label'] }}
                                @endif
                                @if(!$loop->last)<br>@endif
                            @endforeach
                        @endif
                    </td>
                    <td class="status-column">
                        {{ $movement['status'] ?? 'posted' }}
                        @if(!empty($movement['related_canceled_movement_key']))
                            <br><span class="muted">Cancelado por {{ $movement['related_canceled_movement_key'] }}</span>
                        @endif
                        @if(!empty($movement['canceling_movement_key']))
                            <br><span class="muted">Cancela {{ $movement['canceling_movement_key'] }}</span>
                        @endif
                    </td>
                    <td style="text-align:right;" class="amount-column amount-cell {{ $amountClass($movement) }}">
                        {{ $direction === 'out' ? '-' : '' }}{{ $money($movement['amount'] ?? 0) }}
                    </td>
                    <td style="text-align:right;" class="balance-column balance-cell">{{ $movementBalanceText($movement, $movementSection['account']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endif

@if(!$ledgerOnly)
@if($annexOnly)
    <h2>Anexos de recibos y comprobantes</h2>
    @if($receiptAnnexes->isEmpty())
        <table>
            <tbody>
                <tr>
                    <td style="text-align:center;">No hay recibos o comprobantes para los filtros seleccionados.</td>
                </tr>
            </tbody>
        </table>
    @endif
@endif

@if($receiptAnnexGroups->isNotEmpty())
    <div>
        <h2 class="annex-title">Movimientos con recibos o comprobantes</h2>
        <div class="annex-subtitle">Agrupados por tipo y ordenados por Referencia.</div>

        @foreach($receiptAnnexGroups as $groupType => $groupAnnexes)
            @foreach($groupAnnexes as $annex)
                @php
                    $movement = $annex['movement'] ?? [];
                    $receipt = $annex['receipt'] ?? [];
                    $title = $annex['title'] ?? ('Anexo ' . ($annex['reference'] ?? $loop->iteration));
                    $reference = $annex['reference'] ?? '-';
                    $hasPreview = !empty($annex['render_inline_receipt']) || !empty($annex['data_uri']);
                    $isFirstAnnex = $loop->parent->first && $loop->first;
                    $receiptAmount = (float) ($receipt['signed_amount'] ?? (($receipt['direction'] ?? null) === 'out' ? -1 * ($receipt['amount'] ?? 0) : ($receipt['amount'] ?? 0)));
                    $receiptTitle = $receiptAmount < 0 || ($receipt['status'] ?? null) === 'cancellation'
                        ? 'Recibo de cancelacion'
                        : 'Recibo de ingreso';
                @endphp

                @if($hasPreview)
                    <div class="annex-page{{ $isFirstAnnex ? ' annex-page-current' : '' }}">
                        <a name="{{ $annex['anchor'] }}"></a>
                        @if($loop->first)
                            <h3 class="annex-compact-group">
                                {{ $appendixGroupLabels[$groupType] ?? $appendixGroupLabels['other'] }}
                                ({{ $groupAnnexes->count() }})
                            </h3>
                        @endif
                        <h2 class="annex-title">{{ $title }}</h2>
                        <div class="annex-subtitle">{{ $appendixGroupLabels[$groupType] ?? $appendixGroupLabels['other'] }} - Referencia {{ $reference }}</div>

                        @if(!empty($annex['render_inline_receipt']) && !empty($receipt))
                            <div class="inline-receipt">
                                <div class="inline-receipt-top">
                                    <table class="inline-receipt-header">
                                        <tr>
                                            <td style="width:58px;">
                                                @if(!empty($clubLogoDataUri))
                                                    <img src="{{ $clubLogoDataUri }}" class="inline-receipt-logo" alt="Logo">
                                                @endif
                                            </td>
                                            <td>
                                                <div class="inline-receipt-club">{{ $club->club_name ?? 'Club' }}</div>
                                                <div class="inline-receipt-subtitle">{{ $club->church_name ?? '' }}</div>
                                            </td>
                                            <td style="text-align:right;">
                                                <div class="inline-receipt-title">{{ $receiptTitle }}</div>
                                                <div class="inline-receipt-number">{{ $receipt['number'] ?? $reference }}</div>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="inline-receipt-body">
                                    <table class="inline-receipt-grid">
                                        <tr>
                                            <td><div class="annex-label">Fecha de pago</div><div class="annex-value">{{ $receipt['date'] ?? '-' }}</div></td>
                                            <td><div class="annex-label">Emitido</div><div class="annex-value">{{ !empty($receipt['issued_at']) ? substr((string) $receipt['issued_at'], 0, 16) : '-' }}</div></td>
                                            <td><div class="annex-label">Metodo</div><div class="annex-value">{{ $paymentTypeLabel($receipt['payment_type'] ?? null) }}</div></td>
                                        </tr>
                                    </table>
                                    <table class="inline-receipt-detail">
                                        <tr>
                                            <td style="width:50%;"><div class="annex-label">Recibido de</div><div class="annex-value">{{ $receipt['payer'] ?? '-' }}</div></td>
                                            <td style="width:50%;"><div class="annex-label">Registrado por</div><div class="annex-value">{{ $receipt['received_by'] ?? '-' }}</div></td>
                                        </tr>
                                        <tr>
                                            <td><div class="annex-label">Concepto</div><div class="annex-value">{{ $receipt['concept'] ?? '-' }}</div></td>
                                            <td><div class="annex-label">Cuenta / ubicacion</div><div class="annex-value">{{ $receipt['account'] ?? '-' }} / {{ $locationLabel($receipt['location'] ?? null) }}</div></td>
                                        </tr>
                                        <tr>
                                            <td><div class="annex-label">Movimiento</div><div class="annex-value">{{ $receipt['movement_id'] ?? '-' }}</div></td>
                                            <td><div class="annex-label">Email emitido</div><div class="annex-value">{{ $receipt['issued_to_email'] ?? '-' }}</div></td>
                                        </tr>
                                    </table>
                                    <div class="inline-receipt-total">
                                        <div class="annex-label">Total recibido</div>
                                        <div class="inline-receipt-total-value">{{ $signedMoney($receiptAmount) }}</div>
                                    </div>
                                </div>
                            </div>
                        @elseif(!empty($annex['data_uri']))
                            <table class="annex-meta">
                                <tr>
                                    <td><div class="annex-label">Movimiento</div><div class="annex-value">{{ $movement['movement_id'] ?? '-' }}</div></td>
                                    <td><div class="annex-label">Fecha</div><div class="annex-value">{{ $movement['date'] ?? '-' }}</div></td>
                                    <td><div class="annex-label">Monto</div><div class="annex-value">{{ $money($movement['amount'] ?? 0) }}</div></td>
                                    <td><div class="annex-label">Contraparte</div><div class="annex-value">{{ $movement['counterparty'] ?? '-' }}</div></td>
                                    <td><div class="annex-label">Referencia</div><div class="annex-value">{{ $reference }}</div></td>
                                </tr>
                            </table>
                            <table class="annex-meta">
                                <tr><td style="width:100%;"><div class="annex-label">Concepto</div><div class="annex-value">{{ $movement['concept'] ?? '-' }}</div></td></tr>
                            </table>
                            <div class="annex-preview">
                                <img class="annex-image" src="{{ $annex['data_uri'] }}" alt="{{ $reference }}">
                            </div>
                        @endif
                    </div>
                @else
                    <div class="annex-compact-card">
                        <a name="{{ $annex['anchor'] }}"></a>
                        @if($loop->first)
                            <h3 class="annex-compact-group">
                                {{ $appendixGroupLabels[$groupType] ?? $appendixGroupLabels['other'] }}
                                ({{ $groupAnnexes->count() }})
                            </h3>
                        @endif
                        <div class="annex-compact-title">{{ $title }}</div>
                        <div class="annex-compact-meta">
                            Referencia {{ $reference }}
                            · Movimiento {{ $movement['movement_id'] ?? '-' }}
                            · Fecha {{ $movement['date'] ?? '-' }}
                            · Monto {{ $money($movement['amount'] ?? 0) }}
                        </div>
                        <div class="annex-compact-meta">
                            {{ $movement['concept'] ?? '-' }}
                            @if(!empty($movement['counterparty']))
                                · {{ $movement['counterparty'] }}
                            @endif
                        </div>
                        @if(!empty($annex['url']))
                            <div class="annex-compact-link">Archivo original: <a href="{{ $annex['url'] }}">{{ $annex['url'] }}</a></div>
                        @else
                            <div class="annex-compact-link">No hay vista previa disponible para este comprobante.</div>
                        @endif
                        @if(!empty($annex['mime_type']))
                            <div class="annex-compact-meta">Tipo de archivo: {{ $annex['mime_type'] }}</div>
                        @endif
                    </div>
                @endif
            @endforeach
        @endforeach
    </div>
@endif

@if($movementsWithoutReceiptsGroups->isNotEmpty())
    <div class="{{ $receiptAnnexGroups->isNotEmpty() ? 'annex-compact-section' : '' }}">
        <h2 class="annex-title">Movimientos sin recibos o comprobantes</h2>
        <div class="annex-subtitle">Agrupados por tipo y ordenados por Referencia.</div>

        @foreach($movementsWithoutReceiptsGroups as $groupType => $groupMovements)
            <h3 class="annex-compact-group">
                {{ $appendixGroupLabels[$groupType] ?? $appendixGroupLabels['other'] }}
                ({{ $groupMovements->count() }})
            </h3>

            @foreach($groupMovements as $movement)
                <div class="annex-compact-card">
                    <div class="annex-compact-title">{{ $movementReferenceText($movement) }} - {{ $movement['concept'] ?? '-' }}</div>
                    <div class="annex-compact-meta">
                        Referencia {{ $movementReferenceText($movement) }}
                        · Movimiento {{ $movement['movement_id'] ?? '-' }}
                        ·
                        Fecha {{ $movement['date'] ?? '-' }}
                        · Monto {{ $money($movement['amount'] ?? 0) }}
                        · Cuenta {{ $movement['account_label'] ?? $movement['account'] ?? $movement['from_account'] ?? '-' }}
                    </div>
                    <div class="annex-compact-meta">
                        {{ $movement['counterparty'] ?? $movement['created_by'] ?? '-' }}
                    </div>
                </div>
            @endforeach
        @endforeach
    </div>
@endif
@endif
</body>
</html>
