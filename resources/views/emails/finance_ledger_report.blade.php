<p>Hola,</p>

<p>Adjuntamos el reporte financiero del club {{ $club->club_name }}.</p>

<p>
    Club: {{ $club->club_name }}<br>
    Fecha de envio: {{ now()->toDateString() }}<br>
    Archivos adjuntos: {{ count($files ?? []) }}
</p>

@if (!empty($filters['date_from']) || !empty($filters['date_to']) || !empty($filters['account']))
    <p>
        Filtros:<br>
        @if (!empty($filters['account']))
            Cuenta: {{ $filters['account'] }}<br>
        @endif
        @if (!empty($filters['date_from']))
            Desde: {{ $filters['date_from'] }}<br>
        @endif
        @if (!empty($filters['date_to']))
            Hasta: {{ $filters['date_to'] }}<br>
        @endif
    </p>
@endif

<p>Gracias.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
