<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $receiptTitle ?? 'Recibo de ingreso' }}</title>
    <style>
        @page { margin: 24px 28px 92px; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            font-size: 11.5px;
            line-height: 1.4;
            background: #ffffff;
        }

        .page {
            width: 100%;
        }

        .receipt-shell {
            border: 1px solid #d1d5db;
            border-radius: 12px;
            overflow: hidden;
        }

        .top-band {
            background: #ffffff;
            color: #0f172a;
            border-bottom: 1px solid #d1d5db;
            padding: 22px 24px;
        }

        .header-table,
        .meta-table,
        .columns-table,
        .info-table,
        .total-table,
        .validation-footer table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: middle;
        }

        .logo-cell {
            width: 76px;
        }

        .logo-placeholder {
            width: 56px;
            height: 56px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            text-align: center;
            vertical-align: middle;
            font-size: 8px;
            line-height: 1.15;
            color: #64748b;
            padding-top: 17px;
            background: #f8fafc;
        }

        .logo-image {
            width: 56px;
            height: 56px;
            object-fit: contain;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            padding: 4px;
        }

        .org-name {
            font-size: 15px;
            font-weight: bold;
            letter-spacing: .2px;
        }

        .org-subtitle {
            margin-top: 3px;
            font-size: 10.5px;
            color: #64748b;
        }

        .receipt-title-cell {
            text-align: right;
        }

        .receipt-title {
            font-size: 23px;
            font-weight: bold;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: #0f172a;
        }

        .receipt-number-pill {
            display: inline-block;
            margin-top: 7px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #f8fafc;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-size: 11px;
            font-weight: bold;
        }

        .content {
            padding: 22px 24px 18px;
        }

        .meta-panel {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f9fafb;
            margin-bottom: 18px;
        }

        .meta-table td {
            width: 33.333%;
            padding: 12px 14px;
            border-right: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .meta-table td:last-child {
            border-right: 0;
        }

        .label {
            color: #6b7280;
            font-size: 9.5px;
            font-weight: bold;
            letter-spacing: .45px;
            text-transform: uppercase;
        }

        .value {
            margin-top: 4px;
            color: #111827;
            font-size: 12px;
            font-weight: bold;
        }

        .columns-table td {
            width: 50%;
            vertical-align: top;
        }

        .columns-table .left-col {
            padding-right: 8px;
        }

        .columns-table .right-col {
            padding-left: 8px;
        }

        .card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            min-height: 164px;
            overflow: hidden;
        }

        .card-title {
            background: #f3f4f6;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: .35px;
            text-transform: uppercase;
            color: #374151;
        }

        .card-body {
            padding: 10px 12px;
        }

        .info-table td {
            padding: 6px 0;
            vertical-align: top;
            border-bottom: 1px solid #f3f4f6;
        }

        .info-table tr:last-child td {
            border-bottom: 0;
        }

        .info-label {
            width: 42%;
            color: #6b7280;
            font-size: 10px;
        }

        .info-value {
            color: #111827;
            font-size: 11.5px;
            font-weight: bold;
            text-align: right;
            word-break: break-word;
        }

        .total-panel {
            margin-top: 18px;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            background: #eff6ff;
            padding: 14px 16px;
        }

        .total-table td {
            vertical-align: middle;
        }

        .order-panel {
            margin-top: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }

        .order-title {
            background: #f3f4f6;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: .35px;
            text-transform: uppercase;
            color: #374151;
        }

        .order-support {
            margin-top: 2px;
            color: #6b7280;
            font-size: 9.5px;
            font-weight: normal;
            letter-spacing: 0;
            text-transform: none;
        }

        .order-table {
            width: 100%;
            border-collapse: collapse;
        }

        .order-table th {
            background: #f9fafb;
            color: #6b7280;
            font-size: 9.5px;
            letter-spacing: .35px;
            padding: 8px 10px;
            text-align: left;
            text-transform: uppercase;
            border-bottom: 1px solid #e5e7eb;
        }

        .order-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }

        .order-table tr:last-child td {
            border-bottom: 0;
        }

        .order-number {
            text-align: right;
            white-space: nowrap;
        }

        .order-total-row td {
            background: #f9fafb;
            font-weight: bold;
        }

        .total-label {
            color: #1e3a8a;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .total-support {
            margin-top: 3px;
            color: #475569;
            font-size: 10px;
        }

        .total-amount {
            text-align: right;
            color: #1d4ed8;
            font-size: 30px;
            font-weight: bold;
            white-space: nowrap;
        }

        .note-box {
            margin-top: 14px;
            border-left: 3px solid #d1d5db;
            padding: 8px 10px;
            color: #4b5563;
            font-size: 10.5px;
            background: #fafafa;
        }

        .footer {
            margin-top: 18px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 9.5px;
            text-align: center;
        }

        .validation-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -74px;
            height: 66px;
            border-top: 1px solid #d1d5db;
            padding-top: 6px;
            font-size: 8.5px;
            color: #4b5563;
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
    @if(!empty($qrCodeDataUri) && !empty($validationUrl))
        <div class="validation-footer">
            <table>
                <tr>
                    <td style="width: 64px;">
                        <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR de validación">
                    </td>
                    <td>
                        <div><strong>Validación digital:</strong> escanee el QR para confirmar este recibo contra el sistema.</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <div class="page">
        <div class="receipt-shell">
            <div class="top-band">
                <table class="header-table">
                    <tr>
                        <td class="logo-cell">
                            @if(!empty($clubLogoDataUri))
                                <img class="logo-image" src="{{ $clubLogoDataUri }}" alt="Logo del club">
                            @else
                                <div class="logo-placeholder">LOGO<br>CLUB</div>
                            @endif
                        </td>
                        <td>
                            <div class="org-name">{{ $club?->club_name ?? 'Club' }}</div>
                            <div class="org-subtitle">
                                {{ !empty($club?->church_name) ? $club->church_name : 'Sistema de clubes' }}
                            </div>
                        </td>
                        <td class="receipt-title-cell">
                            <div class="receipt-title">{{ $receiptTitle ?? 'Recibo de ingreso' }}</div>
                            <div class="receipt-number-pill">{{ $receipt->receipt_number }}</div>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="content">
                <div class="meta-panel">
                    <table class="meta-table">
                        <tr>
                            <td>
                                <div class="label">Emitido</div>
                                <div class="value">{{ optional($receipt->issued_at)->format('Y-m-d H:i') }}</div>
                            </td>
                            <td>
                                <div class="label">Fecha de pago</div>
                                <div class="value">{{ optional($paymentDate)->format('Y-m-d') ?? '—' }}</div>
                            </td>
                            <td>
                                <div class="label">Recibido por</div>
                                <div class="value">{{ $payment?->receivedBy?->name ?? '—' }}</div>
                            </td>
                        </tr>
                    </table>
                </div>

                <table class="columns-table">
                    <tr>
                        <td class="left-col">
                            <div class="card">
                                <div class="card-title">Recibido de</div>
                                <div class="card-body">
                                    <table class="info-table">
                                        <tr>
                                            <td class="info-label">Destinatario del recibo</td>
                                            <td class="info-value">{{ $recipient_name }}</td>
                                        </tr>
                                        <tr>
                                            <td class="info-label">Correo</td>
                                            <td class="info-value">{{ $recipient_email ?: '—' }}</td>
                                        </tr>
                                        @if($member_name)
                                            <tr>
                                                <td class="info-label">Miembro</td>
                                                <td class="info-value">{{ $member_name }}</td>
                                            </tr>
                                        @endif
                                        @if($staff_name)
                                            <tr>
                                                <td class="info-label">Staff</td>
                                                <td class="info-value">{{ $staff_name }}</td>
                                            </tr>
                                        @endif
                                    </table>
                                </div>
                            </div>
                        </td>
                        <td class="right-col">
                            <div class="card">
                                <div class="card-title">Detalle del ingreso</div>
                                <div class="card-body">
                                    <table class="info-table">
                                        <tr>
                                            <td class="info-label">Concepto</td>
                                            <td class="info-value">{{ $concept_name ?? $payment?->concept?->concept ?? $payment?->concept_text ?? '—' }}</td>
                                        </tr>
                                        @if(!empty($payment?->allocations) && $payment->allocations->isNotEmpty())
                                            @foreach($payment->allocations as $allocation)
                                                <tr>
                                                    <td class="info-label">{{ $allocation->concept?->eventFeeComponent?->label ?? 'Componente' }}</td>
                                                    <td class="info-value">${{ number_format((float) $allocation->amount, 2) }}</td>
                                                </tr>
                                            @endforeach
                                        @endif
                                        <tr>
                                            <td class="info-label">Cuenta</td>
                                            <td class="info-value">{{ $accountLabel ?? '—' }}</td>
                                        </tr>
                                        <tr>
                                            <td class="info-label">Metodo</td>
                                            <td class="info-value">{{ ucfirst($paymentType ?? '—') }}</td>
                                        </tr>
                                        @if(!empty($zellePhone))
                                            <tr>
                                                <td class="info-label">Telefono Zelle</td>
                                                <td class="info-value">{{ $zellePhone }}</td>
                                            </tr>
                                        @endif
                                        @if(!empty($isCancellationReceipt) && !empty($originalPaymentId))
                                            <tr>
                                                <td class="info-label">Movimiento original</td>
                                                <td class="info-value">#{{ $originalPaymentId }}</td>
                                            </tr>
                                        @endif
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="total-panel">
                    <table class="total-table">
                        <tr>
                            <td>
                                <div class="total-label">{{ !empty($isCancellationReceipt) ? 'Importe cancelado' : 'Importe recibido' }}</div>
                                <div class="total-support">
                                    {{ $concept_name ?? $payment?->concept?->concept ?? $payment?->concept_text ?? 'Ingreso registrado' }}
                                </div>
                            </td>
                            <td class="total-amount">
                                ${{ number_format(!empty($isCancellationReceipt) ? abs((float) $amountPaid) : (float) $amountPaid, 2) }}
                            </td>
                        </tr>
                    </table>
                </div>

                @if(!empty($fundraiserOrder['items']))
                    <div class="order-panel">
                        <div class="order-title">
                            Detalle del pedido
                            @if(!empty($fundraiserOrder['event_name']))
                                <div class="order-support">{{ $fundraiserOrder['event_name'] }}</div>
                            @endif
                        </div>
                        <table class="order-table">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th class="order-number">Cantidad</th>
                                    <th class="order-number">Precio</th>
                                    <th class="order-number">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($fundraiserOrder['items'] as $item)
                                    <tr>
                                        <td>{{ $item['name'] ?? 'Producto' }}</td>
                                        <td class="order-number">{{ (int) ($item['quantity'] ?? 0) }}</td>
                                        <td class="order-number">${{ number_format((float) ($item['unit_price'] ?? 0), 2) }}</td>
                                        <td class="order-number">${{ number_format((float) ($item['line_total'] ?? 0), 2) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="order-total-row">
                                    <td colspan="3" class="order-number">Total del pedido</td>
                                    <td class="order-number">${{ number_format((float) ($fundraiserOrder['total_amount'] ?? $payment?->amount_paid ?? 0), 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif

                @if(!empty($notes))
                    <div class="note-box">
                        <strong>Notas:</strong> {{ $notes }}
                    </div>
                @endif

                <div class="footer">
                    Documento generado automaticamente por el sistema de clubes. Escanee el QR para validar este recibo contra el sistema.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
