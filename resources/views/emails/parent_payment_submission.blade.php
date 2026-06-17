@php
    $memberDetail = \App\Support\ClubHelper::memberDetail($submission->member);
@endphp

<p>Hola,</p>

<p>Un padre envio un comprobante de pago desde el portal.</p>

<p>
    Club: {{ $submission->club?->club_name ?? 'Club' }}<br>
    Miembro: {{ $memberDetail['name'] ?? '—' }}<br>
    Padre: {{ $submission->parentUser?->name ?? '—' }}<br>
    Concepto: {{ $submission->concept?->concept ?? $submission->concept_text ?? '—' }}<br>
    Fecha de pago: {{ optional($submission->payment_date)->toDateString() }}<br>
    Importe: ${{ number_format((float) ($submission->amount ?? 0), 2) }}
</p>

@if ($submission->reference)
    <p>Referencia: {{ $submission->reference }}</p>
@endif

@if ($submission->notes)
    <p>Notas: {{ $submission->notes }}</p>
@endif

<p>El comprobante esta adjunto y tambien queda disponible para revision en la plataforma.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
