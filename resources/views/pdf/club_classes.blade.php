<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 24px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 6px; }
        .meta { margin-bottom: 12px; color: #444; }
        .meta div { margin-bottom: 2px; }
        .class-page { page-break-before: always; }
        .class-page.first { page-break-before: auto; }
        .class-title { font-size: 15px; margin: 0 0 8px 0; }
        .class-subtitle { margin: 0 0 8px 0; color: #444; }
        .requirements-block { border: 1px solid #ccc; padding: 10px; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .req-list { margin: 0; padding-left: 16px; }
        .req-list li { margin-bottom: 4px; }
        .empty { text-align: center; color: #666; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        <div><strong>Generado:</strong> {{ $generatedAt }}</div>
        <div><strong>Club:</strong> {{ $clubName ?: '—' }}</div>
        <div><strong>Total clases:</strong> {{ $classes->count() }}</div>
        <div><strong>Filtro de club:</strong> {{ $clubFilter ? ('Club ID ' . $clubFilter) : 'Todos los clubes permitidos' }}</div>
    </div>

    @if(!$withRequirements)
        <table>
            <thead>
                <tr>
                    <th>Orden</th>
                    <th>Clase</th>
                    <th>Staff asignado</th>
                </tr>
            </thead>
            <tbody>
                @forelse($classes as $class)
                    <tr>
                        <td>{{ $class->class_order }}</td>
                        <td>{{ $class->class_name }}</td>
                        <td>{{ $class->assigned_staff_name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty" colspan="3">No hay clases para mostrar.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @else
        @forelse($classes as $idx => $class)
            <div class="class-page {{ $idx === 0 ? 'first' : '' }}">
                <h2 class="class-title">{{ $class->class_name }} (Orden {{ $class->class_order }})</h2>
                <p class="class-subtitle"><strong>Staff asignado:</strong> {{ $class->assigned_staff_name ?? '—' }}</p>
                @php
                    $requirements = collect($class->investitureRequirements ?? [])
                        ->where('is_active', true)
                        ->sortBy([
                            fn ($r) => (int) ($r->sort_order ?? 0),
                            fn ($r) => (int) ($r->id ?? 0),
                        ])
                        ->values();
                @endphp
                <div class="requirements-block">
                    <strong>Requisitos de investidura</strong>
                    @if($requirements->isEmpty())
                        <div>Sin requisitos registrados</div>
                    @else
                        <ol class="req-list">
                            @foreach($requirements as $req)
                                <li>
                                    <strong>{{ $req->title }}</strong>
                                    @if(!empty($req->description))
                                        <div>{{ $req->description }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        @empty
            <div class="empty">No hay clases para mostrar.</div>
        @endforelse
    @endif
</body>
</html>
