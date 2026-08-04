<p>Hola,</p>

<p>Adjuntamos la solicitud anual del club de Aventureros {{ $application->club_name }}.</p>

<p>
    Club: {{ $application->club_name }}<br>
    Iglesia: {{ $application->sponsoring_church }}<br>
    Año: {{ $application->application_year }}<br>
    Fecha de envío: {{ now()->toDateString() }}
</p>

<p>Gracias.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
