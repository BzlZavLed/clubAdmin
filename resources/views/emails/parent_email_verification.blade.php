<!doctype html>
<html lang="es">
<body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937">
<div style="max-width:640px;margin:0 auto;padding:32px 16px">
    <div style="background:#fff;border-radius:12px;padding:32px;border:1px solid #e5e7eb">
        <h1 style="margin:0 0 16px;font-size:24px">Confirma tu correo electrónico</h1>
        <p>Hola {{ $parent->name }}, confirma este correo para activar tu cuenta y entrar al Portal de Padres.</p>
        <p style="margin:24px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#047857;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:700">Confirmar correo y entrar</a></p>
        <p style="font-size:13px;color:#6b7280">Si no puedes recibir o abrir este enlace, comunícate con el director del club. El director puede activar tu cuenta como alternativa.</p>
        <hr style="border:0;border-top:1px solid #e5e7eb;margin:28px 0">
        <h2 style="margin:0 0 12px;font-size:20px">Confirm your email</h2>
        <p>Hello {{ $parent->name }}, confirm this email to activate your account and enter the Parent Portal.</p>
        <p style="margin:24px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#047857;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:700">Confirm email and continue</a></p>
        <p style="font-size:13px;color:#6b7280">If you cannot receive or open this link, contact the club director. The director can activate your account as a fallback.</p>
    </div>
</div>
@if($trackingPixelUrl)<img src="{{ $trackingPixelUrl }}" alt="" width="1" height="1" style="display:none">@endif
</body>
</html>
