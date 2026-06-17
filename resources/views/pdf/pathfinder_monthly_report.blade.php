<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 36px 42px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; font-size: 12px; line-height: 1.45; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 22px 0 8px; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .muted { color: #6b7280; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header > div { display: table-cell; vertical-align: top; }
        .header .right { text-align: right; }
        .grid { display: table; width: 100%; table-layout: fixed; border-collapse: collapse; }
        .row { display: table-row; }
        .cell { display: table-cell; width: 50%; border: 1px solid #e5e7eb; padding: 7px 8px; vertical-align: top; }
        .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .value { font-size: 13px; margin-top: 2px; }
        .metric { display: inline-block; width: 31%; border: 1px solid #e5e7eb; margin: 0 1% 8px 0; padding: 8px; }
        .metric .value { font-size: 20px; font-weight: 700; }
        .text-box { border: 1px solid #e5e7eb; padding: 10px; min-height: 46px; white-space: pre-line; }
        .files { width: 100%; border-collapse: collapse; }
        .files th, .files td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; }
        .files th { background: #f9fafb; }
        .evidence-page { page-break-before: always; }
        .evidence-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .evidence-meta { margin-bottom: 16px; color: #4b5563; }
        .evidence-image-wrap { width: 100%; height: 850px; text-align: center; }
        .evidence-image { max-width: 100%; max-height: 830px; }
        .evidence-note { border: 1px solid #d1d5db; padding: 18px; background: #f9fafb; font-size: 14px; line-height: 1.6; }
    </style>
</head>
<body>
    @php
        $volunteerFiles = $report->attachments->where('kind', 'volunteer_proof')->values();
        $activityFiles = $report->attachments->where('kind', 'activity_photo')->values();
        $metric = fn ($label, $value) => '<div class="metric"><div class="label">' . e($label) . '</div><div class="value">' . e((string) ($value ?? 0)) . '</div></div>';
    @endphp

    <div class="header">
        <div>
            <h1>Pathfinder Club Monthly Report</h1>
            <div class="muted">{{ $report->report_month }} {{ $report->report_year }}</div>
        </div>
        <div class="right">
            <strong>{{ $report->club?->club_name }}</strong><br>
            <span class="muted">Generated {{ now()->format('M j, Y') }}</span>
        </div>
    </div>

    <h2>Club Information</h2>
    <div class="grid">
        <div class="row">
            <div class="cell"><div class="label">Full Name</div><div class="value">{{ $report->full_name ?: '—' }}</div></div>
            <div class="cell"><div class="label">Email</div><div class="value">{{ $report->email ?: '—' }}</div></div>
        </div>
        <div class="row">
            <div class="cell"><div class="label">Area</div><div class="value">{{ $report->area ?: '—' }}</div></div>
            <div class="cell"><div class="label">Church AND Club Name</div><div class="value">{{ $report->church_and_club_name ?: '—' }}</div></div>
        </div>
    </div>

    <h2>Participation</h2>
    {!! $metric('# of Pathfinders', $report->pathfinders_count) !!}
    {!! $metric("# of TLT's", $report->tlt_count) !!}
    {!! $metric('# of Staff', $report->staff_count) !!}

    <h2>This Month's Meeting Info</h2>
    {!! $metric('# of Meetings', $report->meetings_count) !!}
    {!! $metric('# of Bible Studies', $report->bible_studies_count) !!}
    {!! $metric('# of Baptisms', $report->baptisms_count) !!}
    {!! $metric('# of Campouts', $report->campouts_count) !!}
    {!! $metric('# of Field Trips', $report->field_trips_count) !!}
    {!! $metric('# of Honors Completed', $report->honors_completed_count) !!}

    <h2>Honors Completed</h2>
    <div class="text-box">{{ $report->honors_completed_list ?: '—' }}</div>

    <h2>Outreach Activities</h2>
    <div class="text-box">{{ $report->outreach_activities ?: '—' }}</div>

    <h2>Notable Pathfinder Activities</h2>
    <div class="text-box">{{ $report->notable_activities ?: '—' }}</div>

    <h2>Photo Sharing</h2>
    <p>{{ $report->may_share_photos ? 'Yes, photos may be shared.' : 'No or not specified.' }}</p>

    <h2>Uploaded Evidence</h2>
    <table class="files">
        <thead>
            <tr><th>Type</th><th>File</th><th>Size</th></tr>
        </thead>
        <tbody>
            @forelse($report->attachments as $file)
                <tr>
                    <td>{{ $file->kind === 'volunteer_proof' ? 'Verified Volunteer proof' : 'Activity photo/event evidence' }}</td>
                    <td>{{ $file->original_name }}</td>
                    <td>{{ $file->size ? number_format($file->size / 1024, 1) . ' KB' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No files uploaded.</td></tr>
            @endforelse
        </tbody>
    </table>

    @foreach(($evidencePages ?? []) as $page)
        <div class="evidence-page">
            <div class="evidence-title">{{ $page['title'] }}</div>
            <div class="evidence-meta">
                <strong>{{ $page['file_name'] }}</strong><br>
                {{ $page['mime_type'] }}
                @if(!empty($page['size']))
                    · {{ number_format($page['size'] / 1024, 1) }} KB
                @endif
            </div>

            @if($page['type'] === 'image' && !empty($page['data_uri']))
                <div class="evidence-image-wrap">
                    <img class="evidence-image" src="{{ $page['data_uri'] }}" alt="{{ $page['file_name'] }}">
                </div>
            @else
                <div class="evidence-note">
                    {{ $page['note'] }}
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
