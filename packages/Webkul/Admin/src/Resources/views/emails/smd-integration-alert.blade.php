<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px;background:#F3F4F6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#FFFFFF;border-radius:8px;overflow:hidden;">
        <tr>
            <td style="background:#B91C1C;color:#FFFFFF;padding:20px 24px;font-size:20px;font-weight:bold;">
                {{ $type }} · {{ $title }}
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <div style="background:#FEE2E2;border-left:6px solid #B91C1C;padding:16px;font-size:16px;">
                    Pasa desde el <strong>{{ $since }}</strong>:
                    <strong style="color:#B91C1C;font-size:22px;">{{ $count }}</strong> {{ $count === 1 ? 'vez' : 'veces' }} en las últimas 24 h.
                </div>

                <p style="font-size:14px;line-height:1.5;"><strong>Qué revisar:</strong> {{ $check }}</p>

                @if ($context)
                    <p style="font-size:14px;margin-bottom:4px;"><strong>Último caso:</strong></p>
                    <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                        @foreach ($context as $key => $value)
                            <tr style="border-bottom:1px solid #E5E7EB;">
                                <td style="color:#6B7280;">{{ $key }}</td>
                                <td>{{ $value === null ? '—' : (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                <p style="font-size:12px;color:#6B7280;margin-top:24px;">
                    Este aviso se envía como mucho una vez por hora por tipo de error, mientras siga pasando.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
