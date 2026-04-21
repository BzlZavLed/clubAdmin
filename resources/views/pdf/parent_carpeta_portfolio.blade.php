<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Carpeta de investidura</title>
    <style>
        @page { margin: 28px 30px 92px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; line-height: 1.35; }
        h1, h2, h3 { margin: 0; }
        .header { border-bottom: 3px solid #1d4ed8; padding-bottom: 12px; margin-bottom: 14px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: middle; }
        .logo-cell { width: 72px; }
        .club-logo { width: 56px; height: 56px; object-fit: contain; border: 1px solid #d1d5db; border-radius: 8px; padding: 4px; }
        .title { font-size: 22px; font-weight: bold; color: #1d4ed8; }
        .subtitle { margin-top: 4px; color: #4b5563; }
        .grid { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .grid td { border: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        .label { width: 24%; color: #6b7280; background: #f9fafb; font-weight: bold; }
        .value { color: #111827; }
        .section-title { margin: 18px 0 8px; font-size: 15px; color: #111827; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .requirement { page-break-inside: avoid; border: 1px solid #d1d5db; margin-bottom: 12px; }
        .req-head { background: #f3f4f6; padding: 8px 10px; border-bottom: 1px solid #d1d5db; }
        .req-title { font-size: 13px; font-weight: bold; }
        .req-meta { margin-top: 2px; color: #6b7280; font-size: 10px; }
        .req-body { padding: 10px; }
        .description { margin-bottom: 8px; color: #374151; }
        .status { display: inline-block; padding: 3px 7px; border-radius: 10px; font-size: 10px; font-weight: bold; }
        .complete { background: #dcfce7; color: #166534; }
        .pending { background: #fef3c7; color: #92400e; }
        .evidence-box { margin-top: 8px; border: 1px solid #dbeafe; background: #eff6ff; padding: 8px; }
        .image-evidence { margin-top: 8px; max-width: 100%; max-height: 520px; border: 1px solid #d1d5db; }
        .placeholder-page { page-break-before: always; min-height: 820px; border: 2px dashed #9ca3af; padding: 24px; text-align: center; }
        .placeholder-title { margin-top: 160px; font-size: 20px; font-weight: bold; color: #374151; }
        .placeholder-copy { margin: 18px auto 0; width: 80%; font-size: 13px; color: #4b5563; }
        .muted { color: #6b7280; }
        .footer-note { margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #6b7280; font-size: 9px; }
        .validation-footer { position: fixed; left: 0; right: 0; bottom: -74px; height: 66px; border-top: 1px solid #d1d5db; padding-top: 6px; font-size: 8.5px; color: #4b5563; }
        .validation-footer table { width: 100%; border-collapse: collapse; }
        .validation-footer td { vertical-align: top; }
        .qr { width: 56px; height: 56px; }
        .break-all { word-break: break-all; }
    </style>
</head>
<body>
    <div class="validation-footer">
        <table>
            <tr>
                <td style="width: 64px;">
                    <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR de validación">
                </td>
                <td>
                    <div><strong>Validación digital:</strong> escanee el QR para confirmar este PDF contra el sistema.</div>
                    <div class="break-all"><strong>URL:</strong> {{ $validationUrl }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="header">
        <table class="header-table">
            <tr>
                @if(!empty($clubLogoDataUri))
                    <td class="logo-cell">
                        <img class="club-logo" src="{{ $clubLogoDataUri }}" alt="Logo del club">
                    </td>
                @endif
                <td>
                    <div class="title">Carpeta de investidura</div>
                    <div class="subtitle">Documento generado desde evidencia registrada por el padre/tutor.</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="grid">
        <tr>
        <td class="label">{{ $memberLabel ?? 'Adventurero' }}</td>
            <td class="value">{{ $detail?->applicant_name ?? '—' }}</td>
            <td class="label">Fecha</td>
            <td class="value">{{ $generatedAt }}</td>
        </tr>
        <tr>
            <td class="label">Clase</td>
            <td class="value">{{ $className ?? '—' }}</td>
            <td class="label">Grado</td>
            <td class="value">{{ $detail?->grade ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Club</td>
            <td class="value">{{ $club?->club_name ?? '—' }} @if($club?->id) (ID {{ $club->id }}) @endif</td>
            <td class="label">Iglesia</td>
            <td class="value">{{ $church?->church_name ?? $club?->church_name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Distrito</td>
            <td class="value">{{ $district?->name ?? '—' }}</td>
            <td class="label">Unión</td>
            <td class="value">{{ $union?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Pastor</td>
            <td class="value">{{ $church?->pastor_name ?? $club?->pastor_name ?? '—' }}</td>
            <td class="label">Sistema</td>
            <td class="value">{{ $union?->evaluation_system ?? $club?->evaluation_system ?? '—' }}</td>
        </tr>
    </table>

    <h2 class="section-title">Requisitos y evidencias</h2>

    @foreach($requirements as $requirement)
        @php
            $evidence = $requirement['evidence'] ?? null;
            $mode = $requirement['validation_mode'] ?? 'electronic';
        @endphp

        <div class="requirement">
            <div class="req-head">
                <div class="req-title">
                    @if(!empty($requirement['sort_order'])){{ $requirement['sort_order'] }}. @endif{{ $requirement['title'] ?? 'Requisito' }}
                </div>
                <div class="req-meta">
                    Tipo: {{ $requirement['requirement_type'] ?? '—' }} | Validación: {{ $mode }}
                </div>
            </div>
            <div class="req-body">
                @if(!empty($requirement['description']))
                    <div class="description">{{ $requirement['description'] }}</div>
                @endif

                <span class="status {{ !empty($requirement['completed']) ? 'complete' : 'pending' }}">
                    {{ !empty($requirement['completed']) ? 'Evidencia registrada' : 'Pendiente' }}
                </span>

                @if($evidence)
                    <div class="evidence-box">
                        <div><strong>Tipo de evidencia:</strong> {{ $evidence['type'] ?? '—' }}</div>
                        <div><strong>Registrada:</strong> {{ $evidence['submitted_at'] ?? '—' }}</div>

                        @if(!empty($evidence['is_image']) && !empty($evidence['absolute_path']))
                            <div><strong>Imagen adjunta:</strong></div>
                            <img class="image-evidence" src="{{ $evidence['absolute_path'] }}" alt="Evidencia">
                        @endif

                        @if(!empty($evidence['file_url']) && empty($evidence['is_image']))
                            <div><strong>Archivo:</strong> <span class="break-all">{{ $evidence['file_url'] }}</span></div>
                        @endif

                        @if(!empty($evidence['text_value']) && ($evidence['type'] ?? null) !== 'physical_only')
                            <div><strong>Texto / enlace:</strong> <span class="break-all">{{ $evidence['text_value'] }}</span></div>
                        @endif

                        @if(!empty($evidence['physical_completed']))
                            <div><strong>Requisito físico:</strong> marcado como completado.</div>
                        @endif
                    </div>
                @else
                    <p class="muted">No hay evidencia registrada para este requisito.</p>
                @endif
            </div>
        </div>

        @if($mode === 'physical')
            <div class="placeholder-page">
                <div class="placeholder-title">Evidencia física adjunta en siguiente página</div>
                <div class="placeholder-copy">
                    <p><strong>Requisito:</strong> {{ $requirement['title'] ?? 'Requisito' }}</p>
                    @if(!empty($requirement['description']))
                        <p>{{ $requirement['description'] }}</p>
                    @endif
                    <p>Imprima esta carpeta y adjunte aquí la evidencia obtenida por el proceso físico correspondiente.</p>
                </div>
            </div>
        @endif
    @endforeach

    <div class="footer-note">
        Documento generado automáticamente. Escanee el QR para validar este documento contra el sistema.
    </div>
</body>
</html>
