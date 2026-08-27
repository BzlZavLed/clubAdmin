<!doctype html>
<html lang="es">
<body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937">
<div style="max-width:640px;margin:0 auto;padding:32px 16px">
    <div style="background:#fff;border-radius:12px;padding:32px;border:1px solid #e5e7eb">
        <h1 style="margin:0 0 16px;font-size:24px">Crea una nueva contraseña</h1>
        <p>Hola {{ $parent->name }}, usa este enlace único para elegir una nueva contraseña. El enlace vence en 24 horas.</p>
        <p style="margin:24px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:700">Elegir nueva contraseña</a></p>
        <p style="font-size:13px;color:#6b7280">Si no solicitaste este cambio, ignora este mensaje. El enlace deja de funcionar después de utilizarlo.</p>
        <hr style="border:0;border-top:1px solid #e5e7eb;margin:28px 0">
        <h2 style="margin:0 0 12px;font-size:20px">Choose a new password</h2>
        <p>Hello {{ $parent->name }}, use this single-use link to choose a new password. The link expires in 24 hours.</p>
        <p style="margin:24px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:700">Choose new password</a></p>
        <p style="font-size:13px;color:#6b7280">If you did not request this change, ignore this message. The link stops working after it is used.</p>
    </div>
</div>
@if($trackingPixelUrl)<img src="{{ $trackingPixelUrl }}" alt="" width="1" height="1" style="display:none">@endif
</body>
</html>
