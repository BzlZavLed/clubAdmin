<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de deposito de evento</title>
    <style>
        @page { margin: 24px 28px 92px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11.5px; line-height: 1.4; background: #ffffff; }
        .shell { border: 1px solid #d1d5db; border-radius: 12px; overflow: hidden; }
        .top { padding: 22px 24px; border-bottom: 1px solid #d1d5db; }
        .header-table, .meta-table, .detail-table, .breakdown-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: middle; }
        .logo { width: 56px; height: 56px; object-fit: contain; border: 1px solid #cbd5e1; border-radius: 10px; background: #ffffff; padding: 4px; }
        .logo-placeholder { width: 56px; height: 56px; border: 1px solid #cbd5e1; border-radius: 10px; text-align: center; padding-top: 17px; font-size: 8px; color: #64748b; background: #f8fafc; }
        .org-name { font-size: 15px; font-weight: bold; }
        .org-subtitle { margin-top: 3px; font-size: 10.5px; color: #64748b; }
        .receipt-title { text-align: right; font-size: 23px; font-weight: bold; text-transform: uppercase; }
        .pill { display: inline-block; margin-top: 7px; padding: 5px 10px; border-radius: 999px; background: #f8fafc; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 11px; font-weight: bold; }
        .content { padding: 22px 24px 18px; }
        .panel { border: 1px solid #e5e7eb; border-radius: 10px; background: #f9fafb; margin-bottom: 18px; }
        .meta-table td { width: 33.333%; padding: 12px 14px; border-right: 1px solid #e5e7eb; vertical-align: top; }
        .meta-table td:last-child { border-right: 0; }
        .label { color: #6b7280; font-size: 9.5px; font-weight: bold; letter-spacing: .45px; text-transform: uppercase; }
        .value { margin-top: 4px; color: #111827; font-size: 12px; font-weight: bold; }
        .detail-table td { padding: 6px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .detail-table tr:last-child td { border-bottom: 0; }
        .detail-label { width: 42%; color: #6b7280; font-size: 10px; }
        .detail-value { color: #111827; font-size: 11.5px; font-weight: bold; text-align: right; word-break: break-word; }
        .section-title { font-size: 11px; font-weight: bold; letter-spacing: .35px; text-transform: uppercase; color: #374151; margin-bottom: 10px; }
        .breakdown-table th, .breakdown-table td { padding: 8px 10px; border: 1px solid #e5e7eb; }
        .breakdown-table th { background: #f3f4f6; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; }
        .breakdown-table td:last-child, .breakdown-table th:last-child { text-align: right; }
        .total { margin-top: 18px; border: 1px solid #bfdbfe; border-radius: 12px; background: #eff6ff; padding: 14px 16px; }
        .total-amount { text-align: right; color: #1d4ed8; font-size: 30px; font-weight: bold; }
        .note { margin-top: 14px; border-left: 3px solid #d1d5db; padding: 8px 10px; color: #4b5563; font-size: 10.5px; background: #fafafa; }
        .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 9.5px; text-align: center; }
        .qr { width: 92px; height: 92px; }
    </style>
</head>
<body>
    <div class="shell">
        <div class="top">
            <table class="header-table">
                <tr>
                    <td style="width:76px;">
                        @if(!empty($clubLogoDataUri))
                            <img src="{{ $clubLogoDataUri }}" class="logo" alt="Logo club">
                        @else
                            <div class="logo-placeholder">LOGO</div>
                        @endif
                    </td>
                    <td>
                        <div class="org-name">{{ $club?->club_name ?? 'Club' }}</div>
                        <div class="org-subtitle">{{ $club?->church_name ?? '—' }}</div>
                    </td>
                    <td class="receipt-title">
                        <div>RECIBO DE DEPOSITO</div>
                        <div class="pill">{{ $settlement->receipt_number }}</div>
                    </td>
                </tr>
            </table>
        </div>
        <div class="content">
            <div class="panel">
                <table class="meta-table">
                    <tr>
                        <td>
                            <div class="label">Evento</div>
                            <div class="value">{{ $event?->title ?? '—' }}</div>
                        </td>
                        <td>
                            <div class="label">Fecha de deposito</div>
                            <div class="value">{{ optional($settlement->deposited_at)->format('Y-m-d H:i') }}</div>
                        </td>
                        <td>
                            <div class="label">Organizador</div>
                            <div class="value">{{ $organizerLabel }}</div>
                        </td>
                    </tr>
                </table>
            </div>

            <table class="detail-table" style="margin-bottom:18px;">
                <tr>
                    <td class="detail-label">Referencia</td>
                    <td class="detail-value">{{ $settlement->reference ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Registrado por</td>
                    <td class="detail-value">{{ $settlement->creator?->name ?: '—' }}</td>
                </tr>
            </table>

            <div class="section-title">Desglose depositado</div>
            <table class="breakdown-table">
                <thead>
                    <tr>
                        <th>Componente</th>
                        <th>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(($settlement->breakdown_json ?? []) as $row)
                        <tr>
                            <td>{{ $row['label'] ?? 'Componente' }}</td>
                            <td>${{ number_format((float) ($row['amount'] ?? 0), 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="total">
                <table style="width:100%;">
                    <tr>
                        <td>
                            <div class="label" style="color:#1e3a8a;">Total depositado</div>
                        </td>
                        <td class="total-amount">${{ number_format((float) $settlement->amount, 2) }}</td>
                    </tr>
                </table>
            </div>

            @if($settlement->notes)
                <div class="note">{{ $settlement->notes }}</div>
            @endif

            <div class="footer">
                <div>Validacion: {{ $validationUrl }}</div>
                @if(!empty($qrCodeDataUri))
                    <div style="margin-top:8px;"><img src="{{ $qrCodeDataUri }}" class="qr" alt="QR"></div>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
