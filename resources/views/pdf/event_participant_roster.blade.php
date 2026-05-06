<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 18px 18px 82px; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 0; font-size: 10px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #4b5563; line-height: 1.35; margin-bottom: 12px; }
        .label { font-weight: bold; color: #111827; }
        .summary { width: 100%; border-collapse: collapse; margin: 10px 0 12px; }
        .summary td { width: 20%; border: 1px solid #d1d5db; padding: 8px; vertical-align: top; }
        .summary-title { display: block; color: #6b7280; font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 3px; }
        .summary-value { font-size: 14px; font-weight: bold; color: #111827; }
        table.roster { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .roster th { background: #f3f4f6; border: 1px solid #d1d5db; color: #374151; font-size: 9px; padding: 6px; text-align: left; }
        .roster td { border: 1px solid #e5e7eb; padding: 6px; vertical-align: top; word-wrap: break-word; }
        .right { text-align: right; }
        .muted { color: #6b7280; }
        .name { font-weight: bold; color: #111827; }
        .badge { display: inline-block; border: 1px solid #d1d5db; border-radius: 999px; padding: 2px 5px; margin: 0 2px 3px 0; font-size: 8px; font-weight: bold; }
        .badge-green { border-color: #a7f3d0; color: #047857; background: #ecfdf5; }
        .badge-blue { border-color: #bfdbfe; color: #1d4ed8; background: #eff6ff; }
        .optional-list { margin: 3px 0 0; padding-left: 12px; }
        .optional-list li { margin-bottom: 2px; }
        .empty { padding: 18px; text-align: center; color: #6b7280; }
        .validation-footer { position: fixed; left: 0; right: 0; bottom: -64px; height: 58px; border-top: 1px solid #d1d5db; padding-top: 6px; color: #4b5563; font-size: 8px; }
        .validation-footer table { width: 100%; border-collapse: collapse; }
        .validation-footer td { vertical-align: top; }
        .qr { width: 52px; height: 52px; }
    </style>
</head>
<body>
    @php
        $money = fn ($value) => '$' . number_format((float) $value, 2);
        $optionalStatusLabel = fn ($status) => [
            'paid' => 'Opcionales pagados',
            'partial' => 'Opcionales parciales',
            'not_paid' => 'Opcionales pendientes',
            'not_available' => 'Sin opcionales',
        ][$status] ?? 'Sin opcionales';
    @endphp

    <h1>Lista general de participantes</h1>
    <div class="meta">
        <div><span class="label">Evento:</span> {{ $event->title }}</div>
        <div><span class="label">Alcance:</span> {{ $scopeLabel }}</div>
        <div><span class="label">Tipo:</span> {{ $event->event_type ?: '—' }}</div>
        <div><span class="label">Inicio:</span> {{ optional($event->start_at)->format('Y-m-d H:i') ?: '—' }}</div>
        <div><span class="label">Generado:</span> {{ $generatedAt }}</div>
    </div>

    <table class="summary">
        <tr>
            <td><span class="summary-title">Participantes</span><span class="summary-value">{{ $totals['participants'] }}</span></td>
            <td><span class="summary-title">Inscritos</span><span class="summary-value">{{ $totals['enrolled'] }}</span></td>
            <td><span class="summary-title">Confirmados</span><span class="summary-value">{{ $totals['confirmed'] }}</span></td>
            <td><span class="summary-title">Pagado total</span><span class="summary-value">{{ $money($totals['total_paid']) }}</span></td>
            <td><span class="summary-title">Opcionales</span><span class="summary-value">{{ $money($totals['optional_paid']) }}</span></td>
        </tr>
    </table>

    <table class="roster">
        <thead>
            <tr>
                <th style="width: 16%;">Participante</th>
                <th style="width: 8%;">Tipo</th>
                <th style="width: 14%;">Club</th>
                <th style="width: 12%;">Distrito</th>
                @if($showAssociation)
                    <th style="width: 12%;">Asociación</th>
                @endif
                <th style="width: 13%;">Estado</th>
                <th style="width: 11%;" class="right">Pagado</th>
                <th>Opcionales</th>
            </tr>
        </thead>
        <tbody>
            @forelse($roster as $row)
                <tr>
                    <td>
                        <div class="name">{{ $row['name'] ?? '—' }}</div>
                        <div class="muted">{{ $row['participant_key'] ?? '' }}</div>
                    </td>
                    <td>{{ $row['participant_type_label'] ?? '—' }}</td>
                    <td>{{ $row['club_name'] ?? '—' }}</td>
                    <td>{{ $row['district_name'] ?? '—' }}</td>
                    @if($showAssociation)
                        <td>{{ $row['association_name'] ?? '—' }}</td>
                    @endif
                    <td>
                        @if(!empty($row['is_enrolled']))
                            <span class="badge badge-green">Inscrito</span>
                        @endif
                        @if(!empty($row['is_confirmed']))
                            <span class="badge badge-blue">Confirmado</span>
                        @endif
                        @if((float) ($row['required_expected'] ?? 0) > 0)
                            <div class="muted">Obligatorio: {{ $money($row['required_paid'] ?? 0) }} / {{ $money($row['required_expected'] ?? 0) }}</div>
                        @endif
                    </td>
                    <td class="right"><strong>{{ $money($row['total_paid'] ?? 0) }}</strong></td>
                    <td>
                        <div><strong>{{ $optionalStatusLabel($row['optional_status'] ?? 'not_available') }}</strong></div>
                        @if(!empty($row['optional_components']))
                            <ul class="optional-list">
                                @foreach($row['optional_components'] as $component)
                                    <li>
                                        {{ $component['label'] ?? 'Opcional' }}:
                                        {{ $money($component['paid_amount'] ?? 0) }} / {{ $money($component['expected_amount'] ?? 0) }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ $showAssociation ? 8 : 7 }}">Todavía no hay participantes confirmados o inscritos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if(!empty($qrCodeDataUri))
        <div class="validation-footer">
            <table>
                <tr>
                    <td style="width:58px;">
                        <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR de validación">
                    </td>
                    <td>
                        <div><strong>Validación digital:</strong> escanee el QR para confirmar esta lista contra el sistema.</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif
</body>
</html>
