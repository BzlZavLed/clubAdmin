@php
    $application = $signature->application;
    $club = $application?->club;
    $roleLabel = match ($signature->role) {
        'pastor' => 'Church Pastor',
        'head_elder' => 'Head Elder',
        'director' => 'Club Director',
        default => $signature->role,
    };
@endphp

<p>Hola {{ $signature->signer_name ?: $roleLabel }},</p>

<p>Se solicita tu firma para la aplicacion anual Pathfinder del club {{ $club?->club_name }}.</p>

<p>
    Club: {{ $club?->club_name }}<br>
    Iglesia: {{ $application?->sponsoring_church }}<br>
    Año: {{ $application?->application_year }}<br>
    Firma requerida: {{ $roleLabel }}
</p>

<p>
    <a href="{{ $signatureUrl }}">Abrir aplicacion y firmar</a>
</p>

<p>Este enlace es unico para tu firma. No lo compartas.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
