<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Receipt</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; }
        .page { padding: 24px; }
        .header { border-bottom: 2px solid #dbeafe; padding-bottom: 12px; margin-bottom: 20px; }
        .title { font-size: 22px; font-weight: bold; color: #1d4ed8; }
        .subtitle { font-size: 11px; color: #6b7280; margin-top: 4px; }
        .section { margin-top: 18px; }
        .section-title { font-size: 13px; font-weight: bold; margin-bottom: 8px; color: #111827; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid td { padding: 6px 0; vertical-align: top; }
        .label { width: 180px; color: #6b7280; }
        .value { font-weight: bold; color: #111827; }
        .box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
        .footer { margin-top: 28px; font-size: 10px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="title">Recibo de ingreso</div>
            <div class="subtitle">
                {{ $club?->club_name ?? 'Club' }}{{ !empty($club?->church_name) ? ' • ' . $club->church_name : '' }}
            </div>
        </div>

        <div class="box">
            <table class="grid">
                <tr>
                    <td class="label">Numero de recibo</td>
                    <td class="value">{{ $receipt->receipt_number }}</td>
                </tr>
                <tr>
                    <td class="label">Emitido</td>
                    <td class="value">{{ optional($receipt->issued_at)->format('Y-m-d H:i') }}</td>
                </tr>
                <tr>
                    <td class="label">Fecha de pago</td>
                    <td class="value">{{ optional($payment?->payment_date)->format('Y-m-d') ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="label">Recibido por</td>
                    <td class="value">{{ $payment?->receivedBy?->name ?? '—' }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Pagador</div>
            <div class="box">
                <table class="grid">
                    <tr>
                        <td class="label">Destinatario del recibo</td>
                        <td class="value">{{ $recipient_name }}</td>
                    </tr>
                    <tr>
                        <td class="label">Correo</td>
                        <td class="value">{{ $recipient_email ?: '—' }}</td>
                    </tr>
                    @if($member_name)
                        <tr>
                            <td class="label">Miembro</td>
                            <td class="value">{{ $member_name }}</td>
                        </tr>
                    @endif
                    @if($staff_name)
                        <tr>
                            <td class="label">Staff</td>
                            <td class="value">{{ $staff_name }}</td>
                        </tr>
                    @endif
                </table>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Detalle del ingreso</div>
            <div class="box">
                <table class="grid">
                    <tr>
                        <td class="label">Concepto</td>
                        <td class="value">{{ $payment?->concept?->concept ?? $payment?->concept_text ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Cuenta</td>
                        <td class="value">{{ $payment?->account?->label ?? $payment?->pay_to ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Metodo</td>
                        <td class="value">{{ ucfirst($payment?->payment_type ?? '—') }}</td>
                    </tr>
                    <tr>
                        <td class="label">Importe</td>
                        <td class="value">${{ number_format((float) ($payment?->amount_paid ?? 0), 2) }}</td>
                    </tr>
                    @if(!empty($payment?->zelle_phone))
                        <tr>
                            <td class="label">Telefono Zelle</td>
                            <td class="value">{{ $payment->zelle_phone }}</td>
                        </tr>
                    @endif
                    @if(!empty($payment?->notes))
                        <tr>
                            <td class="label">Notas</td>
                            <td class="value">{{ $payment->notes }}</td>
                        </tr>
                    @endif
                </table>
            </div>
        </div>

        <div class="footer">
            Documento generado automaticamente por el sistema de clubes.
        </div>
    </div>
</body>
</html>
