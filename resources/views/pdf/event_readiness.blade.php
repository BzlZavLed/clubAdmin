<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Preparacion del evento</title>
    <style>
        @page { margin: 18px 18px 82px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 10px; line-height: 1.35; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        h2 { margin: 16px 0 8px; font-size: 13px; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .meta { color: #4b5563; margin-bottom: 12px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .summary td { width: 20%; border: 1px solid #d1d5db; padding: 8px; vertical-align: top; }
        .summary-label { display: block; color: #6b7280; font-size: 8px; font-weight: bold; text-transform: uppercase; }
        .summary-value { display: block; margin-top: 3px; font-size: 14px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .data th { background: #f3f4f6; border: 1px solid #d1d5db; color: #374151; font-size: 8.5px; padding: 5px; text-align: left; }
        .data td { border: 1px solid #e5e7eb; padding: 5px; vertical-align: top; word-wrap: break-word; }
        .right { text-align: right; }
        .muted { color: #6b7280; }
        .badge { display: inline-block; border-radius: 999px; padding: 2px 6px; font-size: 8px; font-weight: bold; }
        .ready { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .pending { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .blocked { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
        .section { page-break-inside: avoid; }
        .validation-footer { position: fixed; left: 0; right: 0; bottom: -64px; height: 58px; border-top: 1px solid #d1d5db; padding-top: 6px; color: #4b5563; font-size: 8px; }
        .validation-footer table { width: 100%; border-collapse: collapse; }
        .validation-footer td { border: 0; padding: 0; vertical-align: top; }
        .qr { width: 52px; height: 52px; }
        ul { margin: 4px 0 0 14px; padding: 0; }
        li { margin: 2px 0; }
    </style>
</head>
<body>
    @php
        $money = fn ($value) => '$' . number_format((float) $value, 2);
        $statusClass = fn ($status) => $status === 'ready' ? 'ready' : ($status === 'blocked' ? 'blocked' : 'pending');
        $financialReport = $readiness['financial_report'] ?? ['components' => [], 'clubs' => [], 'participants' => [], 'totals' => []];
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

    <h1>Preparacion del evento</h1>
    <div class="meta">
        <div><strong>Evento:</strong> {{ $event->title }}</div>
        <div><strong>Tipo:</strong> {{ $event->event_type ?: '—' }}</div>
        <div><strong>Inicio:</strong> {{ optional($event->start_at)->format('Y-m-d H:i') ?: '—' }}</div>
        <div><strong>Generado:</strong> {{ $generatedAt }}</div>
    </div>

    <table class="summary">
        <tr>
            <td><span class="summary-label">Clubes</span><span class="summary-value">{{ $readiness['totals']['clubs'] ?? 0 }}</span></td>
            <td><span class="summary-label">Preparacion completa</span><span class="summary-value">{{ $readiness['totals']['ready_clubs'] ?? 0 }}</span></td>
            <td><span class="summary-label">Pendientes</span><span class="summary-value">{{ $readiness['totals']['pending_clubs'] ?? 0 }}</span></td>
            <td><span class="summary-label">Atencion critica</span><span class="summary-value">{{ $readiness['totals']['blocked_clubs'] ?? 0 }}</span></td>
            <td><span class="summary-label">Pendiente depositar</span><span class="summary-value">{{ $money($readiness['totals']['pending_settlement_amount'] ?? 0) }}</span></td>
        </tr>
    </table>

    <div class="section">
        <h2>Reporte financiero del evento - clubes</h2>
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 17%;">Club</th>
                    <th style="width: 13%;">Estado</th>
                    <th style="width: 10%;" class="right">Esperado</th>
                    <th style="width: 10%;" class="right">Pagado</th>
                    <th style="width: 10%;" class="right">Pendiente</th>
                    @foreach(($financialReport['components'] ?? []) as $component)
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
                        @foreach(($financialReport['components'] ?? []) as $component)
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
                        <td colspan="{{ 5 + count($financialReport['components'] ?? []) }}" style="text-align:center; color:#6b7280; padding:16px;">No hay clubes visibles para este reporte.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Reporte financiero del evento - miembros y staff</h2>
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 17%;">Participante</th>
                    <th style="width: 9%;">Tipo</th>
                    <th style="width: 14%;">Club</th>
                    <th style="width: 12%;">Estado</th>
                    <th style="width: 10%;" class="right">Pagado</th>
                    @foreach(($financialReport['components'] ?? []) as $component)
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
                        @foreach(($financialReport['components'] ?? []) as $component)
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
                        <td colspan="{{ 5 + count($financialReport['components'] ?? []) }}" style="text-align:center; color:#6b7280; padding:16px;">No hay pagos o participantes confirmados para desglosar.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>Estado por club</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 18%;">Club</th>
                <th style="width: 16%;">Estado</th>
                <th style="width: 14%;">Inscritos</th>
                <th style="width: 10%;">Tareas</th>
                <th style="width: 10%;">Docs</th>
                <th style="width: 12%;" class="right">Pendiente</th>
                <th>Alertas</th>
            </tr>
        </thead>
        <tbody>
            @forelse(($readiness['clubs'] ?? []) as $club)
                <tr>
                    <td>
                        <strong>{{ $club['club_name'] ?? '—' }}</strong>
                        <div class="muted">{{ $club['district_name'] ?? $club['church_name'] ?? '—' }}</div>
                    </td>
                    <td>
                        <span class="badge {{ $statusClass($club['status'] ?? 'pending') }}">{{ $club['status_label'] ?? '—' }}</span>
                        <div class="muted">{{ $club['signup_status'] ?? '—' }}</div>
                    </td>
                    <td>
                        Miembros: {{ $club['participants']['enrolled_members'] ?? 0 }} / {{ $club['participants']['confirmed_members'] ?? 0 }}<br>
                        Staff: {{ $club['participants']['enrolled_staff'] ?? 0 }} / {{ $club['participants']['confirmed_staff'] ?? 0 }}
                    </td>
                    <td>{{ $club['tasks']['done'] ?? 0 }} / {{ $club['tasks']['total'] ?? 0 }}</td>
                    <td>{{ $club['documents']['uploaded'] ?? 0 }}</td>
                    <td class="right"><strong>{{ $money($club['finance']['pending_settlement_amount'] ?? 0) }}</strong></td>
                    <td>
                        @if(!empty($club['blockers']))
                            <ul>
                                @foreach($club['blockers'] as $blocker)
                                    <li><strong>{{ $blocker['label'] ?? 'Alerta' }}:</strong> {{ $blocker['message'] ?? '' }}</li>
                                @endforeach
                            </ul>
                        @else
                            <span class="muted">Sin alertas activas</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align:center; color:#6b7280; padding:16px;">No hay clubes visibles para este evento.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="section">
        <h2>Recordatorios sugeridos</h2>
        @if(!empty($readiness['reminders']))
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 20%;">Destino</th>
                        <th style="width: 20%;">Razon</th>
                        <th>Mensaje</th>
                        <th style="width: 12%;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($readiness['reminders'] as $reminder)
                        <tr>
                            <td>{{ $reminder['recipient_label'] ?? '—' }}</td>
                            <td>{{ $reminder['reason'] ?? '—' }}</td>
                            <td>{{ $reminder['message'] ?? '—' }}</td>
                            <td>{{ $reminder['processor_status'] ?? 'placeholder' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="muted">No hay recordatorios pendientes.</div>
        @endif
        <div class="muted" style="margin-top:6px;">{{ $readiness['reminder_processor']['message'] ?? '' }}</div>
    </div>

    <div class="section">
        <h2>Cierre del evento</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Checklist</th>
                    <th style="width: 18%;">Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach(($readiness['closeout']['checks'] ?? []) as $check)
                    <tr>
                        <td>{{ $check['label'] ?? '—' }}</td>
                        <td>{{ !empty($check['complete']) ? 'Completo' : 'Pendiente' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
