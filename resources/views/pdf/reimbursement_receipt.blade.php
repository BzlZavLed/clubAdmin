<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de reembolso #{{ $receipt['id'] }}</title>
    <style>
        @page { margin: 32px 32px 92px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            font-size: 12px;
            line-height: 1.45;
        }
        .header {
            border-bottom: 2px solid #111827;
            padding-bottom: 14px;
            margin-bottom: 22px;
        }
        .muted { color: #6b7280; }
        .title {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }
        .amount {
            font-size: 28px;
            font-weight: 700;
            text-align: right;
        }
        .grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .grid td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #e5e7eb;
            padding: 12px;
        }
        .label {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 4px;
        }
        .value {
            font-size: 14px;
            font-weight: 700;
        }
        .box {
            border: 1px solid #e5e7eb;
            padding: 12px;
            margin-bottom: 16px;
        }
        .signature {
            height: 96px;
            max-width: 320px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            margin-top: 8px;
        }
        .footer {
            margin-top: 28px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #6b7280;
        }
        .validation-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -72px;
            height: 64px;
            border-top: 1px solid #d1d5db;
            padding-top: 6px;
            font-size: 8.5px;
            color: #4b5563;
        }
        .validation-footer table {
            width: 100%;
            border-collapse: collapse;
        }
        .validation-footer td {
            vertical-align: top;
        }
        .qr {
            width: 56px;
            height: 56px;
        }
        .break-all {
            word-break: break-all;
        }
    </style>
</head>
<body>
    @php
        $settlementLocation = $receipt['settlement_location'] ?? null;
    @endphp

    @if(!empty($qrCodeDataUri) && !empty($validationUrl))
        <div class="validation-footer">
            <table>
                <tr>
                    <td style="width: 64px;">
                        <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR de validacion">
                    </td>
                    <td>
                        <div><strong>Validacion digital:</strong> escanee el QR para confirmar este recibo contra el sistema.</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <table class="header" style="width: 100%;">
        <tr>
            <td>
                <p class="muted" style="margin: 0 0 4px;">Recibo de reembolso</p>
                <h1 class="title">{{ $receipt['club_name'] ?? 'Club' }}</h1>
                <p style="margin: 6px 0 0;">Reembolso #{{ $receipt['id'] }}</p>
            </td>
            <td class="amount">${{ number_format((float) ($receipt['amount'] ?? 0), 2) }}</td>
        </tr>
    </table>

    <table class="grid">
        <tr>
            <td>
                <div class="label">Persona reembolsada</div>
                <div class="value">{{ $receipt['reimbursed_to'] ?? 'Persona reembolsada' }}</div>
                @if(!empty($receipt['payee']['email']))
                    <div class="muted">{{ $receipt['payee']['email'] }}</div>
                @endif
                @if(!empty($receipt['payee']['phone']))
                    <div class="muted">{{ $receipt['payee']['phone'] }}</div>
                @endif
            </td>
            <td>
                <div class="label">Liquidacion</div>
                <div class="value">{{ $receipt['settlement_date'] ?? 'Pendiente' }}</div>
                <div>{{ $receipt['settlement_account_label'] ?? $receipt['settlement_account'] ?? 'Cuenta no definida' }}</div>
                <div class="muted">{{ $settlementLocation === 'bank' ? 'Banco' : ($settlementLocation === 'cash' ? 'Efectivo' : ($settlementLocation ?? 'Origen no definido')) }}</div>
                @if(!empty($receipt['settled_by']))
                    <div class="muted">Registrado por {{ $receipt['settled_by'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if(!empty($receipt['origin_expense']))
        <div class="box">
            <div class="label">Gasto relacionado #{{ $receipt['origin_expense']['id'] }}</div>
            <div class="value">{{ $receipt['origin_expense']['description'] ?? 'Sin descripcion' }}</div>
            <div class="muted">
                {{ $receipt['origin_expense']['expense_date'] ?? 'Sin fecha' }}
                · ${{ number_format((float) ($receipt['origin_expense']['amount'] ?? 0), 2) }}
            </div>
        </div>
    @endif

    <div class="box">
        <div class="label">Confirmacion</div>
        <p style="margin: 0 0 10px;">
            {{ $receipt['signer_name'] ?? $receipt['reimbursed_to'] ?? 'La persona reembolsada' }}
            confirma haber recibido el reembolso completo por
            <strong>${{ number_format((float) ($receipt['amount'] ?? 0), 2) }}</strong>.
        </p>
        <div class="muted">Firmado: {{ $receipt['signed_at'] ?? 'Pendiente' }}</div>
        @if($signatureDataUri)
            <img src="{{ $signatureDataUri }}" class="signature" alt="Firma">
        @endif
    </div>

    <div class="footer">
        Documento generado el {{ optional($generatedAt)->format('Y-m-d H:i') }}.
        Este recibo fue generado desde la confirmacion digital del reembolso.
    </div>
</body>
</html>
