<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Resumen de clases y miembros</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 24px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 16px 0 6px 0; }
        .document-header { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .document-header td { vertical-align: middle; }
        .logo-cell { width: 62px; }
        .club-logo { width: 50px; height: 50px; object-fit: contain; border: 1px solid #ddd; border-radius: 7px; padding: 3px; }
        .meta { margin-bottom: 10px; color: #444; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .empty { color: #666; font-style: italic; margin-top: 4px; }
    </style>
</head>
<body>
    <table class="document-header">
        <tr>
            @if(!empty($clubLogoDataUri))
                <td class="logo-cell"><img class="club-logo" src="{{ $clubLogoDataUri }}" alt="Logo del club"></td>
            @endif
            <td><h1>Resumen de clases y miembros</h1></td>
        </tr>
    </table>
    <div class="meta">
        <div><strong>Club:</strong> {{ $club->club_name ?? '—' }}</div>
        <div><strong>Generado:</strong> {{ $generatedAt }}</div>
        <div><strong>Campos opcionales:</strong>
            {{ $options['include_contact'] ? ' Contacto' : '' }}
            {{ $options['include_parent'] ? ' Padre/Madre' : '' }}
            {{ $options['include_dob'] ? ' DOB' : '' }}
            {{ $options['include_address'] ? ' Direccion' : '' }}
            @if(!$options['include_contact'] && !$options['include_parent'] && !$options['include_dob'] && !$options['include_address'])
                Ninguno
            @endif
        </div>
    </div>

    @forelse($classes as $class)
        <h2>{{ $class['class_name'] }} (Orden {{ $class['class_order'] }})</h2>
        <div><strong>Personal asignado:</strong> {{ $class['assigned_staff_name'] ?? '—' }}</div>

        @if(collect($class['members'])->isEmpty())
            <div class="empty">No hay miembros asignados a esta clase.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nombre</th>
                        @if($options['include_contact'])
                            <th>Contacto</th>
                        @endif
                        @if($options['include_parent'])
                            <th>Padre/Madre</th>
                        @endif
                        @if($options['include_dob'])
                            <th>DOB</th>
                        @endif
                        @if($options['include_address'])
                            <th>Direccion</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($class['members'] as $idx => $member)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>{{ $member['applicant_name'] ?? '—' }}</td>
                            @if($options['include_contact'])
                                <td>
                                    {{ $member['cell_number'] ?? '—' }}
                                    @if(!empty($member['email_address']))
                                        <div>{{ $member['email_address'] }}</div>
                                    @endif
                                </td>
                            @endif
                            @if($options['include_parent'])
                                <td>
                                    {{ $member['parent_name'] ?? '—' }}
                                    @if(!empty($member['parent_cell']))
                                        <div>{{ $member['parent_cell'] }}</div>
                                    @endif
                                </td>
                            @endif
                            @if($options['include_dob'])
                                <td>{{ !empty($member['birthdate']) ? \Carbon\Carbon::parse($member['birthdate'])->toDateString() : '—' }}</td>
                            @endif
                            @if($options['include_address'])
                                <td>{{ $member['home_address'] ?? $member['mailing_address'] ?? '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @empty
        <div class="empty">No hay clases registradas.</div>
    @endforelse
</body>
</html>
