<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; color: #111; margin: 20px; font-size: 12px; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        .document-header { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .document-header td { vertical-align: middle; }
        .logo-cell { width: 62px; }
        .club-logo { width: 50px; height: 50px; object-fit: contain; border: 1px solid #ddd; border-radius: 7px; padding: 3px; }
        h2 { font-size: 15px; margin: 16px 0 6px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
        h3 { font-size: 13px; margin: 12px 0 4px; }
        .meta { font-size: 11px; color: #444; margin-bottom: 12px; line-height: 1.4; }
        .section { margin-bottom: 12px; page-break-inside: avoid; }
        .muted { color: #666; }
        ul { margin: 6px 0 0 14px; padding: 0; }
        li { margin: 3px 0; }
        .driver-card { border: 1px solid #ddd; border-radius: 6px; padding: 8px; margin: 8px 0; page-break-inside: avoid; }
        .label { font-weight: bold; }
    </style>
</head>
<body>
    @php
        $imageDataUri = function (?string $relativePath): ?string {
            if (!$relativePath) {
                return null;
            }

            $fullPath = storage_path('app/public/' . ltrim($relativePath, '/'));
            if (!file_exists($fullPath) || !is_file($fullPath)) {
                return null;
            }

            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => null,
            };

            if (!$mime) {
                return null;
            }

            $bytes = @file_get_contents($fullPath);
            if ($bytes === false) {
                return null;
            }

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        };
    @endphp

    <table class="document-header">
        <tr>
            @if(!empty($clubLogoDataUri))
                <td class="logo-cell"><img class="club-logo" src="{{ $clubLogoDataUri }}" alt="Logo del club"></td>
            @endif
            <td><h1>Event Planner Report</h1></td>
        </tr>
    </table>
    <div class="meta">
        <div><span class="label">Event:</span> {{ $event->title }}</div>
        <div><span class="label">Club:</span> {{ $event->club?->club_name ?? '—' }}</div>
        <div><span class="label">Event Type:</span> {{ $event->event_type }}</div>
        <div><span class="label">Start:</span> {{ optional($event->start_at)->format('Y-m-d H:i') }}</div>
        <div><span class="label">End:</span> {{ optional($event->end_at)->format('Y-m-d H:i') }}</div>
        <div><span class="label">Transportation Mode:</span> {{ $transport_mode === 'private' ? 'Private Cars' : ($transport_mode === 'rental' ? 'Rental Vehicles' : 'Not selected') }}</div>
    </div>

    <h2>Plan Outline</h2>
    @if(!empty($sections))
        @foreach($sections as $section)
            <div class="section">
                <h3>{{ $section['name'] ?? 'Section' }}</h3>
                @if(!empty($section['summary']))
                    <div class="muted">{{ $section['summary'] }}</div>
                @endif
                @if(!empty($section['items']) && is_array($section['items']))
                    <ul>
                        @foreach($section['items'] as $item)
                            <li>
                                <span class="label">{{ $item['label'] ?? 'Item' }}</span>
                                @if(!empty($item['detail'])) — {{ $item['detail'] }} @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @else
        <div class="muted">No outline sections available.</div>
    @endif

    <h2>Transportation Drivers</h2>
    @if(!empty($drivers))
        @foreach($drivers as $driver)
            <div class="driver-card">
                <div><span class="label">Driver:</span> {{ $driver['name'] ?? '—' }}</div>
                <div><span class="label">License #:</span> {{ $driver['license_number'] ?: '—' }}</div>
                <div><span class="label">License Doc:</span> {{ $driver['license_doc_title'] ?: 'Missing' }}</div>
                <div><span class="label">License File:</span> {{ $driver['license_doc_path'] ?: '—' }}</div>
                @php $licenseImage = $imageDataUri($driver['license_doc_path'] ?? null); @endphp
                @if($licenseImage)
                    <div style="margin-top:6px;">
                        <div class="label">License Preview:</div>
                        <img src="{{ $licenseImage }}" alt="License image" style="max-width: 480px; max-height: 260px; border: 1px solid #ddd; padding: 2px;">
                    </div>
                @endif

                @if(!empty($driver['private_mode']))
                    <div style="margin-top:6px;"><span class="label">Vehicles (Private Mode)</span></div>
                    @if(!empty($driver['vehicles']))
                        <ul style="margin-bottom: 4px;">
                            @foreach($driver['vehicles'] as $vehicle)
                                <li>
                                    {{ trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['year'] ?? '')) ?: 'Vehicle' }}
                                    @if(!empty($vehicle['plate'])) — Plate: {{ $vehicle['plate'] }} @endif
                                    @if(!empty($vehicle['vin'])) — VIN: {{ $vehicle['vin'] }} @endif
                                    — Insurance Doc: {{ $vehicle['insurance_doc_title'] ?: 'Missing' }}
                                    @if(!empty($vehicle['insurance_doc_path'])) ({{ $vehicle['insurance_doc_path'] }}) @endif
                                </li>
                                @php $insuranceImage = $imageDataUri($vehicle['insurance_doc_path'] ?? null); @endphp
                                @if($insuranceImage)
                                    <div style="margin: 4px 0 8px 0;">
                                        <img src="{{ $insuranceImage }}" alt="Insurance image" style="max-width: 420px; max-height: 220px; border: 1px solid #ddd; padding: 2px;">
                                    </div>
                                @endif
                            @endforeach
                        </ul>
                    @else
                        <div class="muted">No vehicles assigned.</div>
                    @endif
                @endif
            </div>
        @endforeach
    @else
        <div class="muted">No drivers registered.</div>
    @endif
</body>
</html>
