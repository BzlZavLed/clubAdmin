<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 34px 42px; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #050505;
            font-size: 13px;
            line-height: 1.22;
        }
        .top {
            text-align: center;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.3px;
            margin-bottom: 18px;
        }
        .rule { border-top: 1px solid #111; margin-bottom: 18px; }
        .header { position: relative; min-height: 114px; }
        .title { font-size: 31px; font-weight: 800; margin-bottom: 18px; }
        .logo { position: absolute; right: 0; top: -22px; width: 120px; height: 120px; object-fit: contain; }
        .field-row { display: table; width: 100%; margin: 3px 0; table-layout: fixed; }
        .field-label { display: table-cell; width: 190px; font-size: 16px; font-weight: 800; white-space: nowrap; }
        .field-line { display: table-cell; border-bottom: 1px solid #111; min-height: 18px; padding: 0 6px 2px; font-size: 14px; }
        .columns { display: table; width: 100%; table-layout: fixed; margin-top: 28px; }
        .column { display: table-cell; width: 50%; vertical-align: top; }
        .column:first-child { padding-right: 28px; }
        .column:last-child { padding-left: 28px; }
        h2 { font-size: 14px; margin: 0 0 16px; font-weight: 800; }
        p { margin: 0 0 13px; text-align: justify; }
        .signature-rule { border-top: 1px solid #111; margin: 16px 0 4px; height: 1px; }
        .sig-title { font-size: 13px; margin-bottom: 16px; }
        .sig-row { display: table; width: 100%; table-layout: fixed; margin: 14px 0; }
        .sig-label { display: table-cell; width: 140px; font-size: 13px; }
        .sig-line { display: table-cell; border-bottom: 1px solid #111; padding: 0 6px 2px; min-height: 16px; }
        .sig-image { max-height: 34px; max-width: 190px; vertical-align: bottom; }
        .footer { display: table; width: 100%; table-layout: fixed; margin-top: 32px; font-weight: 800; font-size: 13px; }
        .footer div { display: table-cell; width: 50%; vertical-align: top; }
        .footer div:first-child { padding-right: 28px; }
        .footer div:last-child { padding-left: 28px; }
    </style>
</head>
<body>
    @php
        $line = fn ($value) => e($value ?: '');
        $applicationYear = (int) $application->application_year;
        $dueYear = $applicationYear > 0 ? $applicationYear - 1 : now()->year - 1;
        $due = 'October 10, ' . $dueYear;
        $signaturesByRole = $signaturesByRole ?? collect();
        $signatureImages = $signatureImages ?? [];
        $signatureMarkup = function (string $role) use ($signaturesByRole, $signatureImages, $line) {
            $signature = $signaturesByRole->get($role);
            if (!$signature || !$signature->signed_at) {
                return '';
            }
            if (($signature->signature_type === 'drawn') && !empty($signatureImages[$role])) {
                return '<img class="sig-image" src="' . e($signatureImages[$role]) . '" alt="">';
            }

            return $line($signature->signature_text ?: $signature->signer_name);
        };
        $logoPath = public_path('images/pathfinder.webp');
        $logoData = is_file($logoPath) ? 'data:image/webp;base64,' . base64_encode(file_get_contents($logoPath)) : null;
    @endphp

    <div class="top">DUE {{ strtoupper($due) }}</div>
    <div class="rule"></div>

    <div class="header">
        @if($logoData)
            <img class="logo" src="{{ $logoData }}" alt="Pathfinder">
        @endif
        <div class="title">Pathfinder Club Yearly Application</div>
        <div class="field-row">
            <div class="field-label">Sponsoring Church</div>
            <div class="field-line">{!! $line($application->sponsoring_church) !!}</div>
        </div>
        <div class="field-row">
            <div class="field-label">Pastor</div>
            <div class="field-line">{!! $line($application->pastor) !!}</div>
        </div>
        <div class="field-row">
            <div class="field-label">Elected Club Director</div>
            <div class="field-line">{!! $line($application->elected_club_director) !!}</div>
        </div>
        <div class="field-row">
            <div class="field-label">Mailing Address</div>
            <div class="field-line">{!! $line($application->mailing_address) !!}</div>
        </div>
        <div class="field-row">
            <div class="field-label">Director's Phone Number</div>
            <div class="field-line">{!! $line($application->director_phone_number) !!}</div>
        </div>
    </div>

    <div class="columns">
        <div class="column">
            <h2>The Philosophy of Pathfindering</h2>
            <p>The purpose of having a Pathfinder Club is to lead its membership into a growing, redemptive relationship with Christ, and to build its membership into responsible, mature individuals and to involve its membership in active selfless service. All Pathfinder leaders are Christians, working hand in hand with parents, teachers, and pastors providing optimum opportunities for Christian development. The Pathfinder Club is an extension of the home, school and church, it is an experiential environment where growth and learning flourish. The membership involves youth in grades 5-10 who have a desire for group activities ranging from community and world mission projects to nature, out door work and camping activities.</p>

            <div class="signature-rule"></div>
            <div class="sig-title">Signatures:</div>
            <div class="sig-row"><div class="sig-label">Church Pastor</div><div class="sig-line">{!! $signatureMarkup('pastor') !!}</div></div>
            <div class="sig-row"><div class="sig-label">Head Elder</div><div class="sig-line">{!! $signatureMarkup('head_elder') !!}</div></div>
            <div class="sig-row"><div class="sig-label">Club Director</div><div class="sig-line">{!! $signatureMarkup('director') !!}</div></div>
            <div class="sig-row"><div class="sig-label">Date of Board Approval</div><div class="sig-line">{{ $application->board_approval_date ? $application->board_approval_date->format('m/d/Y') : '' }}</div></div>
        </div>

        <div class="column">
            <p>AY Pathfindering class curriculum and AY Honors. Above all, Pathfindering gives youth an environment in which to actively expand their personal experience with Christ.</p>

            <h2>Your Commitment to Pathfindering</h2>
            <p>We, the undersigned, have read, understand, and are in full agreement with the above Philosophy of Pathfindering and agree to support our club through those means with which the Lord has blessed this church, including finances, staff volunteers, securing a place to meet, transportation on outings, and other such needs as may arise in the fulfillment of this ministry, and to assist and support the work of the Pathfinder ministry in this conference and around the world.</p>
        </div>
    </div>

    <div class="footer">
        <div>The Pathfinder Club Yearly Application is sent to every church in the Chesapeake Conference by the Youth Department. The purpose is to</div>
        <div>allow the church leadership to purposefully request to the Chesapeake Conference that they are interested in sponsoring a Pathfinder Club.</div>
    </div>
</body>
</html>
