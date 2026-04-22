<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Plan de trabajo de unión</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 12mm 25mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; line-height: 1.35; }
        h1, h2, h3, p { margin: 0; }
        .document-header { border: 1px solid #d1d5db; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
        .eyebrow { font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #6b7280; }
        .title { margin-top: 3px; font-size: 22px; font-weight: 800; color: #111827; }
        .subtitle { margin-top: 4px; color: #4b5563; }
        .meta-grid { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .meta-grid td { width: 33.33%; border: 1px solid #e5e7eb; background: #f9fafb; padding: 8px; vertical-align: top; }
        .meta-label { display: block; font-size: 8px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6b7280; }
        .meta-value { display: block; margin-top: 2px; font-size: 12px; font-weight: 700; color: #111827; }
        .summary { margin-bottom: 12px; padding: 8px 10px; border-left: 4px solid #991b1b; background: #fef2f2; color: #7f1d1d; }
        .month { margin-top: 12px; page-break-inside: avoid; }
        .month-title { padding: 7px 9px; border: 1px solid #d1d5db; border-bottom: 0; background: #f3f4f6; font-size: 13px; font-weight: 800; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f9fafb; color: #6b7280; font-size: 8px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; text-align: left; }
        th, td { border: 1px solid #d1d5db; padding: 7px; vertical-align: top; }
        .date-cell { width: 17mm; font-weight: 800; color: #111827; white-space: nowrap; }
        .time-cell { width: 23mm; color: #374151; white-space: nowrap; }
        .title-cell { font-weight: 800; color: #111827; }
        .muted { color: #6b7280; }
        .description { margin-top: 3px; color: #4b5563; }
        .pill { display: inline-block; margin: 1px 2px 1px 0; padding: 2px 6px; border-radius: 999px; font-size: 8px; font-weight: 700; }
        .pill-general { background: #dbeafe; color: #1d4ed8; }
        .pill-program { background: #fef3c7; color: #92400e; }
        .pill-required { border: 1px solid #b91c1c; color: #b91c1c; }
        .pill-target { background: #f3f4f6; color: #4b5563; }
        .empty { margin-top: 18px; padding: 22px; border: 1px dashed #d1d5db; border-radius: 10px; text-align: center; color: #6b7280; }
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
                        <div><strong>Validación digital:</strong> escanee el QR para confirmar este plan de trabajo contra el sistema.</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    @php
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        $clubTypeLabels = [
            'pathfinders' => 'Conquistadores',
            'adventurers' => 'Aventureros',
            'master_guide' => 'Guías Mayores',
        ];
        $eventsByMonth = $events->groupBy(fn ($event) => (int) $event->date->format('n'));
    @endphp

    <header class="document-header">
        <div class="eyebrow">Plan de trabajo de unión</div>
        <h1 class="title">{{ $union->name }}</h1>
        <p class="subtitle">Calendario general de actividades para la unión.</p>

        <table class="meta-grid">
            <tr>
                <td>
                    <span class="meta-label">Año</span>
                    <span class="meta-value">{{ $year }}</span>
                </td>
                <td>
                    <span class="meta-label">Eventos activos</span>
                    <span class="meta-value">{{ $events->count() }}</span>
                </td>
                <td>
                    <span class="meta-label">Generado</span>
                    <span class="meta-value">{{ $generatedAt->format('Y-m-d H:i') }}</span>
                </td>
            </tr>
        </table>
    </header>

    <div class="summary">
        Este documento lista los eventos generales y programas publicados por la unión para el año seleccionado.
    </div>

    @if($events->isEmpty())
        <div class="empty">Sin eventos activos para {{ $year }}.</div>
    @else
        @foreach($eventsByMonth as $monthNumber => $monthEvents)
            <section class="month">
                <h2 class="month-title">{{ $months[$monthNumber] ?? 'Mes' }} · {{ $monthEvents->count() }} evento(s)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Evento</th>
                            <th>Aplica a</th>
                            <th>Lugar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($monthEvents as $event)
                            <tr>
                                <td class="date-cell">
                                    {{ $event->date->format('d/m') }}
                                    @if($event->end_date && !$event->end_date->isSameDay($event->date))
                                        <div class="muted">al {{ $event->end_date->format('d/m') }}</div>
                                    @endif
                                </td>
                                <td class="time-cell">
                                    @if($event->start_time)
                                        {{ substr($event->start_time, 0, 5) }}
                                        @if($event->end_time)
                                            - {{ substr($event->end_time, 0, 5) }}
                                        @endif
                                    @else
                                        <span class="muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    <div>
                                        <span class="pill {{ $event->event_type === 'program' ? 'pill-program' : 'pill-general' }}">
                                            {{ $event->event_type === 'program' ? 'Programa' : 'General' }}
                                        </span>
                                        @if($event->is_mandatory)
                                            <span class="pill pill-required">Obligatorio</span>
                                        @endif
                                    </div>
                                    <div class="title-cell">{{ $event->title }}</div>
                                    @if($event->description)
                                        <div class="description">{{ $event->description }}</div>
                                    @endif
                                </td>
                                <td>
                                    @forelse(($event->target_club_types ?? []) as $clubType)
                                        <span class="pill pill-target">{{ $clubTypeLabels[$clubType] ?? $clubType }}</span>
                                    @empty
                                        <span class="muted">Todos los clubes</span>
                                    @endforelse
                                </td>
                                <td>{{ $event->location ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach
    @endif
</body>
</html>
