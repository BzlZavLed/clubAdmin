<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Balances</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 10mm 25mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1,h2 { margin: 0 0 6px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #f5f5f5; }
        .document-header { width: 100%; border-collapse: collapse; margin: 0 0 12px; border-bottom: 1px solid #d1d5db; }
        .document-header td { border: 0; padding: 0 0 8px; vertical-align: middle; }
        .logo-cell { width: 18mm; padding-right: 8px !important; }
        .club-logo { width: 15mm; height: 15mm; object-fit: contain; border: 1px solid #d1d5db; border-radius: 6px; padding: 2px; }
        .generated-copy { margin: 0; color: #4b5563; }
        .section { margin-bottom: 18px; }
        .validation-footer { position: fixed; left: 0; right: 0; bottom: -19mm; height: 17mm; border-top: 1px solid #d1d5db; padding-top: 2mm; font-size: 8px; color: #4b5563; }
        .validation-footer table { width: 100%; border-collapse: collapse; margin: 0; }
        .validation-footer td { border: 0; padding: 0; vertical-align: top; }
        .qr { width: 14mm; height: 14mm; }
        .break-all { word-break: break-all; }
    </style>
</head>
<body>
    @if(!empty($qrCodeDataUri) && !empty($validationUrl))
        <div class="validation-footer">
            <table>
                <tr>
                    <td style="width: 17mm;">
                        <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR de validación">
                    </td>
                    <td>
                        <div><strong>Validación digital:</strong> escanee el QR para confirmar este reporte contra el sistema.</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <table class="document-header">
        <tr>
            @if(!empty($clubLogoDataUri))
                <td class="logo-cell">
                    <img class="club-logo" src="{{ $clubLogoDataUri }}" alt="Logo del club">
                </td>
            @endif
            <td>
                <h1>Account Balances</h1>
                <p class="generated-copy">{{ $club->club_name ?? 'Club' }}</p>
                @if(!empty($generatedAt))
                    <p class="generated-copy">Generated: {{ $generatedAt->format('Y-m-d H:i') }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="section">
        <h2>Accounts</h2>
        <table>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Entries</th>
                    <th>Expenses</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts as $acc)
                    <tr>
                        <td>{{ $acc['label'] ?? $acc['account'] }}</td>
                        <td>${{ number_format($acc['entries'] ?? 0, 2) }}</td>
                        <td>${{ number_format($acc['expenses'] ?? 0, 2) }}</td>
                        <td>${{ number_format($acc['balance'] ?? 0, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Income entries</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Account</th>
                    <th>Concept</th>
                    <th>Payer</th>
                    <th>Amount</th>
                    <th>Type</th>
                    <th>Receipt Ref</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $p)
                    <tr>
                        <td>{{ optional($p['payment_date'])->format('Y-m-d') ?? $p['payment_date'] }}</td>
                        <td>{{ $p['account_label'] ?? $p['account'] }}</td>
                        <td>{{ $p['concept'] }}</td>
                        <td>{{ $p['member']['applicant_name'] ?? $p['staff']['name'] ?? '—' }}</td>
                        <td>${{ number_format($p['amount_paid'] ?? 0, 2) }}</td>
                        <td>{{ ucfirst($p['payment_type'] ?? '') }}</td>
                        <td>{{ $p['receipt_ref'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Expenses</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Account</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Expense Receipt Ref</th>
                    <th>Reimbursement Receipt Ref</th>
                    <th>Reimbursed to</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach($expenses as $e)
                    <tr>
                        <td>{{ optional($e['expense_date'])->format('Y-m-d') ?? $e['expense_date'] }}</td>
                        <td>{{ $e['pay_to_label'] ?? $e['pay_to'] }}</td>
                        <td>${{ number_format($e['amount'] ?? 0, 2) }}</td>
                        <td>{{ $e['status'] ? ucfirst($e['status']) : '—' }}</td>
                        <td>{{ $e['receipt_ref'] ?? '—' }}</td>
                        <td>{{ $e['reimbursement_receipt_ref'] ?? '—' }}</td>
                        <td>{{ $e['reimbursed_to'] ?? '—' }}</td>
                        <td>{{ $e['description'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if(!empty($receipts) && count($receipts))
        @foreach($receipts as $receipt)
            <div style="page-break-before: always;"></div>
            <h2>Receipt {{ $receipt['ref'] ?? '' }}</h2>
            <p style="margin-bottom: 3px;">{{ $receipt['source'] ?? 'Entry' }} ID: {{ $receipt['record_id'] ?? '' }} | File: {{ $receipt['filename'] ?? '' }}</p>
            @if(!empty($receipt['data_uri']))
                <div style="width: 80%; text-align: center;">
                    <img src="{{ $receipt['data_uri'] }}" style="max-width: 400px; max-height: 400px; width: 400px; height: auto; object-fit: contain; border: 1px solid #ddd; padding: 4px;" />
                </div>
            @else
                <p>Receipt image not available.</p>
            @endif
        @endforeach
    @endif
</body>
</html>
