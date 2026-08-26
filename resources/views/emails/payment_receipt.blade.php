@php
    $payment = $receipt->payment;
    $fundraiserSale = $receipt->fundraiserSale;
    $club = $receipt->club ?? $payment?->club;
@endphp

<p>Hola,</p>

<p>Adjuntamos el recibo de pago {{ $receipt->receipt_number }}.</p>

<p>
    Club: {{ $club?->club_name ?? 'Club' }}<br>
    Fecha: {{ optional($payment?->payment_date ?? $fundraiserSale?->sale_date)->toDateString() ?? optional($receipt->issued_at)->toDateString() }}<br>
    Importe: ${{ number_format((float) ($payment?->amount_paid ?? $fundraiserSale?->total_amount ?? 0), 2) }}
</p>

<p>Gracias.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">ID de correo: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
