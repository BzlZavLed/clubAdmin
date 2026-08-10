<p>Hello,</p>

<p>Attached is the Adventurer induction attendance request for {{ $inductionRequest->club_name }}.</p>

<p>
    Club: {{ $inductionRequest->club_name }}<br>
    Date: {{ optional($inductionRequest->induction_date)->format('F j, Y') }}<br>
    Time: {{ \Carbon\CarbonImmutable::parse($inductionRequest->induction_time)->format('g:i A') }}<br>
    Place: {{ $inductionRequest->induction_place }}<br>
    Requested attendee: {{ $inductionRequest->requested_attendee }}
</p>

<p>Thank you.</p>

@if (!empty($emailUid))
    <p style="font-size: 11px; color: #6b7280;">Email ID: {{ $emailUid }}</p>
@endif

@if (!empty($trackingPixelUrl))
    <img src="{{ $trackingPixelUrl }}" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />
@endif
