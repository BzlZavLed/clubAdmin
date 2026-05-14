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
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 5px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-weight: 700; }
        .header { display: table; width: 100%; margin-bottom: 12px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; }
        .header-right { text-align: right; width: 180px; }
        .logo { max-height: 52px; max-width: 80px; }
        .muted { color: #6b7280; }
        .summary { display: table; width: 100%; margin: 10px 0 14px; }
        .summary-card { display: table-cell; border: 1px solid #d1d5db; padding: 8px; width: 33.3%; }
        .amount-in { color: #047857; font-weight: 700; }
        .amount-out { color: #be123c; font-weight: 700; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; border-top: 1px solid #d1d5db; padding-top: 4px; font-size: 8px; color: #6b7280; }
        .footer img { height: 34px; float: right; }
    </style>
</head>
<body>
@php
    $money = fn ($value) => '$' . number_format((float) ($value ?? 0), 2);
    $movements = collect($report['movements'] ?? []);
    $summary = $report['summary'] ?? [];
@endphp

<div class="footer">
    @if(!empty($qrCodeDataUri))
        <img src="{{ $qrCodeDataUri }}" alt="QR">
    @endif
    Documento generado desde el motor financiero. Validar con el codigo QR.
</div>

<div class="header">
    <div class="header-left">
        <h1>Libro contable financiero</h1>
        <div class="muted">{{ $club->club_name ?? 'Club' }}</div>
        <div class="muted">Generado: {{ optional($generatedAt)->format('Y-m-d H:i') }}</div>
    </div>
    <div class="header-right">
        @if(!empty($clubLogoDataUri))
            <img class="logo" src="{{ $clubLogoDataUri }}" alt="Logo">
        @endif
    </div>
</div>

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
<table>
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <th>Cuenta</th>
            <th>Ubicacion</th>
            <th>Concepto</th>
            <th>Contraparte</th>
            <th>Recibo / comprobante</th>
            <th>Estado</th>
            <th style="text-align:right;">Monto</th>
        </tr>
    </thead>
    <tbody>
        @forelse($movements as $movement)
            @php
                $direction = $movement['direction'] ?? null;
                $receipt = $movement['receipt']['number'] ?? null;
                $proof = $movement['proof']['type'] ?? null;
            @endphp
            <tr>
                <td>{{ $movement['date'] ?? '-' }}</td>
                <td>{{ $movement['domain'] ?? '-' }}<br><span class="muted">{{ $movement['kind'] ?? '' }}</span></td>
                <td>{{ $movement['account_label'] ?? $movement['account'] ?? $movement['from_account'] ?? '-' }}</td>
                <td>{{ $movement['location'] ?? $movement['from_location'] ?? '-' }}</td>
                <td>{{ $movement['concept'] ?? '-' }}</td>
                <td>{{ $movement['counterparty'] ?? $movement['created_by'] ?? '-' }}</td>
                <td>
                    {{ $receipt ?: '-' }}
                    @if($proof)
                        <br><span class="muted">{{ $proof }}</span>
                    @endif
                </td>
                <td>
                    {{ $movement['status'] ?? 'posted' }}
                    @if(!empty($movement['related_canceled_movement_key']))
                        <br><span class="muted">Cancelado por {{ $movement['related_canceled_movement_key'] }}</span>
                    @endif
                    @if(!empty($movement['canceling_movement_key']))
                        <br><span class="muted">Cancela {{ $movement['canceling_movement_key'] }}</span>
                    @endif
                </td>
                <td style="text-align:right;" class="{{ $direction === 'out' ? 'amount-out' : 'amount-in' }}">
                    {{ $direction === 'out' ? '-' : '' }}{{ $money($movement['amount'] ?? 0) }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9" style="text-align:center;">No hay movimientos para los filtros seleccionados.</td>
            </tr>
        @endforelse
    </tbody>
</table>
</body>
</html>
