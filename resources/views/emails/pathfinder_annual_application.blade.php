<p>Hola,</p>

<p>Adjuntamos la aplicacion anual del club Pathfinder {{ $application->club?->club_name }}.</p>

<p>
    Club: {{ $application->club?->club_name }}<br>
    Iglesia: {{ $application->sponsoring_church }}<br>
    Año: {{ $application->application_year }}<br>
    Fecha de envio: {{ now()->toDateString() }}
</p>

<p>Gracias.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
