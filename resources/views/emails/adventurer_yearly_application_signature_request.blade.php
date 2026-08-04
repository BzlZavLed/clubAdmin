@php
    $application = $signature->application;
    $roleLabel = match ($signature->role) {
        'pastor' => 'Pastor de la iglesia',
        'head_elder' => 'Anciano principal',
        'church_clerk' => 'Secretario de iglesia',
        'director' => 'Director del club',
        default => $signature->role,
    };
@endphp

<p>Hola {{ $signature->signer_name ?: $roleLabel }},</p>

<p>Se solicita tu firma para la solicitud anual del club de Aventureros {{ $application?->club_name }}.</p>

<p>
    Club: {{ $application?->club_name }}<br>
    Iglesia: {{ $application?->sponsoring_church }}<br>
    Año: {{ $application?->application_year }}<br>
    Firma requerida: {{ $roleLabel }}
</p>

<p><a href="{{ $signatureUrl }}">Abrir solicitud y firmar</a></p>

<p>Este enlace es único para tu firma. No lo compartas.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
