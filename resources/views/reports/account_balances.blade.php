<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Balances</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1,h2 { margin: 0 0 6px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #f5f5f5; }
        .section { margin-bottom: 18px; }
    </style>
</head>
<body>
    <h1>Account Balances</h1>
    <p>{{ $club->club_name ?? 'Club' }}</p>

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
                        <td>{{ $e['reimbursed_to'] ?? '—' }}</td>
                        <td>{{ $e['description'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
