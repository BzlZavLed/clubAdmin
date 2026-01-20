@php
    use Carbon\Carbon;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Workplan Table</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 24px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { margin-bottom: 10px; color: #444; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 6px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
    <h1>Workplan Table</h1>
    <div class="meta">
        <div><strong>Club:</strong> {{ $workplan->club->club_name ?? 'N/A' }}</div>
        <div><strong>Range:</strong> {{ Carbon::parse($workplan->start_date)->toDateString() }} to {{ Carbon::parse($workplan->end_date)->toDateString() }}</div>
        <div><strong>Total events:</strong> {{ $workplan->events->count() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="nowrap">Fecha</th>
                <th class="nowrap">Hora</th>
                <th>Nombre</th>
                <th>Descripcion</th>
                <th>Lugar</th>
                <th>Objetivo</th>
                <th>Departamento</th>
                <th class="nowrap">Tipo de evento</th>
            </tr>
        </thead>
        <tbody>
            @forelse($workplan->events as $event)
                @php
                    $start = $event->start_time ? substr($event->start_time, 0, 5) : '';
                    $end = $event->end_time ? substr($event->end_time, 0, 5) : '';
                    $time = trim($start . ($end ? ' - ' . $end : ''));
                    $departmentName = $event->department_id ? ($departments[(string) $event->department_id] ?? '') : '';
                    $objectiveName = $event->objective_id ? ($objectives[(string) $event->objective_id] ?? '') : '';
                @endphp
                <tr>
                    <td class="nowrap">{{ optional($event->date)->format('Y-m-d') }}</td>
                    <td class="nowrap">{{ $time ?: '—' }}</td>
                    <td>{{ $event->title ?: '—' }}</td>
                    <td>{{ $event->description ?: '—' }}</td>
                    <td>{{ $event->location ?: '—' }}</td>
                    <td>{{ $objectiveName ?: ($event->objective_id ? ('ID ' . $event->objective_id) : '—') }}</td>
                    <td>{{ $departmentName ?: ($event->department_id ? ('ID ' . $event->department_id) : '—') }}</td>
                    <td class="nowrap">{{ $event->meeting_type ? ucfirst($event->meeting_type) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align:center;">No events found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
