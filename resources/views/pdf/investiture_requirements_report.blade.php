<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $itemLabelPlural }} por Clase</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { margin: 0 0 10px; font-size: 18px; }
        h2 { margin: 0 0 6px; font-size: 15px; }
        h3 { margin: 0 0 6px; font-size: 13px; }
        .meta { margin-bottom: 16px; }
        .meta p { margin: 2px 0; }
        .class-card {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 18px;
            page-break-inside: avoid;
        }
        .requirement-card {
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            margin-top: 10px;
            page-break-inside: avoid;
        }
        .summary { font-size: 11px; color: #374151; margin-bottom: 8px; }
        .muted { color: #6b7280; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 6px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: top;
            text-align: left;
        }
        th { background: #f3f4f6; }
        .pending-list { margin: 8px 0 0; padding-left: 18px; }
        .pending-list li { margin: 2px 0; }
    </style>
</head>
<body>
    <h1>{{ $itemLabelPlural }} por clase</h1>

    <div class="meta">
        <p><strong>Club:</strong> {{ $club['club_name'] ?? '—' }}</p>
        <p><strong>Generado:</strong> {{ $generatedAt }}</p>
        <p><strong>Mostrar pendientes:</strong> {{ $showPending ? 'Sí' : 'No' }}</p>
    </div>

    @forelse($classes as $clubClass)
        <div class="class-card">
            <h2>
                @if(!empty($clubClass['class_order'])){{ $clubClass['class_order'] }}. @endif{{ $clubClass['class_name'] ?? 'Clase' }}
            </h2>
            <div class="summary">
                Miembros: {{ $clubClass['members_count'] ?? 0 }} |
                {{ $itemLabelPlural }}: {{ $clubClass['requirements_count'] ?? 0 }}
            </div>

            @forelse($clubClass['requirements'] ?? [] as $requirement)
                @php
                    $memberIdsCompleted = collect($requirement['completions'] ?? [])->pluck('member_id')->map(fn ($id) => (int) $id)->all();
                    $pendingMembers = collect($clubClass['members'] ?? [])->reject(fn ($member) => in_array((int) ($member['id'] ?? 0), $memberIdsCompleted, true))->values();
                @endphp

                <div class="requirement-card">
                    <h3>
                        @if(!empty($requirement['sort_order'])){{ $requirement['sort_order'] }}. @endif{{ $requirement['title'] ?? $itemLabelSingular }}
                    </h3>
                    @if(!empty($requirement['description']))
                        <div class="muted">{{ $requirement['description'] }}</div>
                    @endif
                    <div class="summary">
                        Completados: {{ $requirement['completed_count'] ?? 0 }} |
                        Pendientes: {{ $requirement['pending_count'] ?? 0 }}
                    </div>

                    @if(empty($requirement['completions']))
                        <p class="muted">Aún no hay miembros con este {{ strtolower($itemLabelSingular) }} cumplido.</p>
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

                    @if($showPending)
                        <div style="margin-top: 8px;">
                            <strong>Miembros pendientes</strong>
                            @if($pendingMembers->isEmpty())
                                <p class="muted">No hay pendientes.</p>
                            @else
                                <ul class="pending-list">
                                    @foreach($pendingMembers as $member)
                                        <li>{{ $member['name'] ?? '—' }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="muted">Esta clase no tiene {{ strtolower($itemLabelPlural) }} configurados.</p>
            @endforelse
        </div>
    @empty
        <p class="muted">No hay clases configuradas para el club activo.</p>
    @endforelse
</body>
</html>
