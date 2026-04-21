<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Requisitos de Investidura por Clase</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { margin: 0 0 10px; font-size: 18px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .header-table td { vertical-align: middle; }
        .logo-cell { width: 62px; }
        .club-logo { width: 50px; height: 50px; object-fit: contain; border: 1px solid #d1d5db; border-radius: 7px; padding: 3px; }
        .meta { margin-bottom: 16px; }
        .meta p { margin: 2px 0; }
        .req-card {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .req-title { font-size: 14px; font-weight: bold; margin-bottom: 6px; }
        .req-desc { font-size: 11px; color: #4b5563; margin-bottom: 8px; }
        .summary { font-size: 11px; color: #374151; margin-bottom: 8px; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: top;
            text-align: left;
        }
        th { background: #f3f4f6; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            @if(!empty($clubLogoDataUri))
                <td class="logo-cell"><img class="club-logo" src="{{ $clubLogoDataUri }}" alt="Logo del club"></td>
            @endif
            <td><h1>Requisitos de investidura por clase</h1></td>
        </tr>
    </table>

    <div class="meta">
        <p><strong>Club:</strong> {{ $club['club_name'] ?? '—' }}</p>
        <p><strong>Clase:</strong> {{ $assignedClass['name'] ?? '—' }} @if(!empty($assignedClass['order'])) (Orden {{ $assignedClass['order'] }}) @endif</p>
        <p><strong>Staff:</strong> {{ $staff['name'] ?? '—' }}</p>
        <p><strong>Miembros en clase:</strong> {{ $membersCount }}</p>
        <p><strong>Generado:</strong> {{ $generatedAt }}</p>
    </div>

    @forelse($requirements as $requirement)
        <div class="req-card">
            <div class="req-title">
                @if(!empty($requirement['sort_order'])){{ $requirement['sort_order'] }}. @endif{{ $requirement['title'] ?? 'Requisito' }}
            </div>
            @if(!empty($requirement['description']))
                <div class="req-desc">{{ $requirement['description'] }}</div>
            @endif
            <div class="summary">
                Completados: {{ $requirement['completed_count'] ?? 0 }} de {{ $membersCount }}
            </div>

            @if(empty($requirement['completions']))
                <p class="muted">Aún no hay miembros con este requisito cumplido.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40%;">Miembro</th>
                            <th style="width: 20%;">Fecha</th>
                            <th style="width: 40%;">Actividad vinculada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($requirement['completions'] as $entry)
                            <tr>
                                <td>{{ $entry['member_name'] ?? '—' }}</td>
                                <td>{{ $entry['date'] ?? '—' }}</td>
                                <td>{{ $entry['activity_title'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        <p class="muted">No hay requisitos configurados para esta clase.</p>
    @endforelse
</body>
</html>
