<p>Hola,</p>

<p>Adjuntamos el reporte mensual Pathfinder del club {{ $report->club?->club_name }}.</p>

<p>
    Club: {{ $report->club?->club_name }}<br>
    Iglesia/Club: {{ $report->church_and_club_name }}<br>
    Mes: {{ $report->report_month }} {{ $report->report_year }}<br>
    Evidencias adjuntas: {{ max(0, count($files ?? []) - 1) }}
</p>

<p>Gracias.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
