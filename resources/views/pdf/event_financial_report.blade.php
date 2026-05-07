<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte financiero del evento</title>
    <style>
        @page { margin: 18px 18px 82px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 9px; line-height: 1.3; }
        h1 { margin: 0 0 4px; font-size: 19px; }
        h2 { margin: 14px 0 8px; font-size: 12px; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .meta { color: #4b5563; margin-bottom: 10px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .summary td { width: 20%; border: 1px solid #d1d5db; padding: 7px; vertical-align: top; }
        .summary-label { display: block; color: #6b7280; font-size: 7.5px; font-weight: bold; text-transform: uppercase; }
        .summary-value { display: block; margin-top: 3px; font-size: 13px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .data th { background: #f3f4f6; border: 1px solid #d1d5db; color: #374151; font-size: 7.5px; padding: 4px; text-align: left; }
        .data td { border: 1px solid #e5e7eb; padding: 4px; vertical-align: top; word-wrap: break-word; }
        .right { text-align: right; }
        .muted { color: #6b7280; font-size: 7.5px; }
        .section { page-break-inside: avoid; }
        .validation-footer { position: fixed; left: 0; right: 0; bottom: -64px; height: 58px; border-top: 1px solid #d1d5db; padding-top: 6px; color: #4b5563; font-size: 8px; }
        .validation-footer table { width: 100%; border-collapse: collapse; }
        .validation-footer td { border: 0; padding: 0; vertical-align: top; }
        .qr { width: 52px; height: 52px; }
    </style>
</head>
<body>
    @php
        $money = fn ($value) => '$' . number_format((float) $value, 2);
        $components = $financialReport['components'] ?? [];
        $componentAmount = fn ($row, $component) => ($row['component_amounts'] ?? [])[(string) $component['id']] ?? ($row['component_amounts'] ?? [])[$component['id']] ?? ['paid_amount' => 0, 'expected_amount' => 0];
    @endphp

    @if(!empty($qrCodeDataUri))
        <div class="validation-footer">
            <table>
                <tr>
                    <td style="width:58px;">
                        <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR de validación">
                    </td>
                    <td>
                        <div><strong>Validación digital:</strong> escanee el QR para confirmar este reporte contra el sistema.</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <h1>Reporte financiero del evento</h1>
    <div class="meta">
        <div><strong>Evento:</strong> {{ $event->title }}</div>
        <div><strong>Tipo:</strong> {{ $event->event_type ?: '—' }}</div>
        <div><strong>Filtro:</strong> {{ $includeTargeted ? 'Incluye clubes targeted sin pagos' : 'Solo clubes con pagos registrados' }}</div>
        <div><strong>Desglose:</strong> {{ $includeBreakdown ? 'Incluye miembros y staff' : 'Solo listado general por club' }}</div>
        <div><strong>Generado:</strong> {{ $generatedAt }}</div>
    </div>

    <table class="summary">
        <tr>
            <td><span class="summary-label">Clubes</span><span class="summary-value">{{ $financialReport['totals']['clubs'] ?? 0 }}</span></td>
            <td><span class="summary-label">Participantes</span><span class="summary-value">{{ $financialReport['totals']['participants'] ?? 0 }}</span></td>
            <td><span class="summary-label">Esperado obligatorio</span><span class="summary-value">{{ $money($financialReport['totals']['expected_amount'] ?? 0) }}</span></td>
            <td><span class="summary-label">Pagado</span><span class="summary-value">{{ $money($financialReport['totals']['paid_amount'] ?? 0) }}</span></td>
            <td><span class="summary-label">Pendiente depositar</span><span class="summary-value">{{ $money($financialReport['totals']['pending_settlement_amount'] ?? 0) }}</span></td>
        </tr>
    </table>

    <div class="section">
        <h2>Clubes</h2>
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 17%;">Club</th>
                    <th style="width: 13%;">Estado</th>
                    <th style="width: 10%;" class="right">Esperado</th>
                    <th style="width: 10%;" class="right">Pagado</th>
                    <th style="width: 10%;" class="right">Pendiente</th>
                    @foreach($components as $component)
                        <th class="right">
                            {{ $component['label'] ?? 'Concepto' }}
                            <div class="muted">{{ !empty($component['is_required']) ? 'Obligatorio' : 'Opcional' }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse(($financialReport['clubs'] ?? []) as $club)
                    <tr>
                        <td>
                            <strong>{{ $club['club_name'] ?? '—' }}</strong>
                            <div class="muted">{{ $club['district_name'] ?? '—' }}</div>
                        </td>
                        <td>
                            {{ $club['status_label'] ?? '—' }}
                            <div class="muted">{{ $club['signup_status'] ?? '—' }}</div>
                        </td>
                        <td class="right">{{ $money($club['expected_amount'] ?? 0) }}</td>
                        <td class="right">{{ $money($club['paid_amount'] ?? 0) }}</td>
                        <td class="right">{{ $money($club['pending_settlement_amount'] ?? 0) }}</td>
                        @foreach($components as $component)
                            @php($amount = $componentAmount($club, $component))
                            <td class="right">
                                <strong>{{ $money($amount['paid_amount'] ?? 0) }}</strong>
                                @if((float) ($amount['expected_amount'] ?? 0) > 0)
                                    <div class="muted">de {{ $money($amount['expected_amount'] ?? 0) }}</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 5 + count($components) }}" style="text-align:center; color:#6b7280; padding:16px;">No hay clubes para este filtro.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($includeBreakdown)
        <div class="section">
            <h2>Miembros y staff</h2>
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 17%;">Participante</th>
                        <th style="width: 9%;">Tipo</th>
                        <th style="width: 14%;">Club</th>
                        <th style="width: 12%;">Estado</th>
                        <th style="width: 10%;" class="right">Pagado</th>
                        @foreach($components as $component)
                            <th class="right">
                                {{ $component['label'] ?? 'Concepto' }}
                                <div class="muted">{{ !empty($component['is_required']) ? 'Obligatorio' : 'Opcional' }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse(($financialReport['participants'] ?? []) as $participant)
                        <tr>
                            <td>
                                <strong>{{ $participant['name'] ?? '—' }}</strong>
                                <div class="muted">{{ $participant['participant_key'] ?? '' }}</div>
                            </td>
                            <td>{{ $participant['participant_type_label'] ?? '—' }}</td>
                            <td>
                                {{ $participant['club_name'] ?? '—' }}
                                <div class="muted">{{ $participant['district_name'] ?? '—' }}</div>
                            </td>
                            <td>
                                @if(!empty($participant['is_enrolled'])) Inscrito @endif
                                @if(!empty($participant['is_confirmed'])) Confirmado @endif
                                @if(empty($participant['is_enrolled']) && empty($participant['is_confirmed'])) Pago registrado @endif
                            </td>
                            <td class="right">{{ $money($participant['paid_amount'] ?? 0) }}</td>
                            @foreach($components as $component)
                                @php($amount = $componentAmount($participant, $component))
                                <td class="right">
                                    <strong>{{ $money($amount['paid_amount'] ?? 0) }}</strong>
                                    @if((float) ($amount['expected_amount'] ?? 0) > 0)
                                        <div class="muted">de {{ $money($amount['expected_amount'] ?? 0) }}</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 5 + count($components) }}" style="text-align:center; color:#6b7280; padding:16px;">No hay pagos o participantes para este filtro.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</body>
</html>
