<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Validación de documento</title>
    <style>
        body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f3f4f6; color: #111827; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { max-width: 760px; width: 100%; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 12px 30px rgba(15, 23, 42, .08); overflow: hidden; }
        .head { padding: 28px; color: #fff; }
        .valid { background: #047857; }
        .invalid { background: #b91c1c; }
        .title { font-size: 26px; font-weight: 800; margin: 0; }
        .subtitle { margin-top: 8px; opacity: .92; }
        .body { padding: 28px; }
        .grid { width: 100%; border-collapse: collapse; margin-top: 18px; }
        .grid td { border-bottom: 1px solid #e5e7eb; padding: 10px 0; vertical-align: top; }
        .label { color: #6b7280; width: 190px; font-weight: 700; }
        .note { margin-top: 18px; border-radius: 10px; background: #eff6ff; color: #1e3a8a; padding: 14px; font-size: 14px; }
        .danger { background: #fef2f2; color: #7f1d1d; }
        @media (max-width: 640px) {
            .wrap { padding: 12px; align-items: stretch; }
            .card { border-radius: 12px; }
            .head, .body { padding: 20px; }
            .title { font-size: 22px; }
            .grid td { display: block; width: 100%; padding: 6px 0; }
            .label { padding-top: 12px; }
        }
    </style>
</head>
<body>
    <main class="wrap">
        <section class="card">
            <header class="head {{ $isValid ? 'valid' : 'invalid' }}">
                <h1 class="title">{{ $isValid ? 'Documento válido' : 'Documento no válido' }}</h1>
                <div class="subtitle">
                    {{ $isValid
                        ? 'Este código fue generado por el sistema para un documento oficial.'
                        : 'No encontramos este código de validación en el sistema.' }}
                </div>
            </header>

            <div class="body">
                @if($isValid)
                    <table class="grid">
                        <tr>
                            <td class="label">Tipo</td>
                            <td>{{ $validation->document_type }}</td>
                        </tr>
                        <tr>
                            <td class="label">Documento</td>
                            <td>{{ $validation->title }}</td>
                        </tr>
                        @foreach($metadata as $label => $value)
                            <tr>
                                <td class="label">{{ $label }}</td>
                                <td>{{ is_scalar($value) ? $value : json_encode($value) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="label">Generado</td>
                            <td>{{ optional($validation->generated_at)->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="label">Validaciones</td>
                            <td>{{ $validation->validation_count }}</td>
                        </tr>
                    </table>

                    <div class="note">
                        Compare los datos de esta página con el PDF impreso. Si el PDF fue alterado o el QR fue copiado desde otro documento, los datos pueden no coincidir.
                    </div>
                @else
                    <div class="note danger">
                        Este documento no puede ser autenticado. Verifique que el QR fue escaneado correctamente o genere una copia nueva desde el sistema.
                    </div>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
