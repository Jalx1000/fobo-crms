@php
    $danger = $level === 'danger';
    $color = $danger ? '#B91C1C' : '#B45309';
    $bg = $danger ? '#FEE2E2' : '#FEF3C7';
@endphp
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px;background:#F3F4F6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#FFFFFF;border-radius:8px;overflow:hidden;">
        <tr>
            <td style="background:{{ $color }};color:#FFFFFF;padding:20px 24px;font-size:20px;font-weight:bold;">
                {{ $danger ? 'PELIGRO: consumo alto de la API de SMD' : 'Aviso: consumo de la API de SMD' }}
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <div style="background:{{ $bg }};border-left:6px solid {{ $color }};padding:16px;font-size:16px;">
                    Hoy ({{ $date }}) el CRM lleva <strong style="color:{{ $color }};font-size:22px;">{{ $count }}</strong>
                    consultas a ShareMeData. {{ $danger ? 'Pasó' : 'Llegó a' }} el límite de <strong>{{ $threshold }}</strong> por día.
                </div>

                <p style="font-size:14px;line-height:1.5;">
                    @if ($danger)
                        <strong style="color:{{ $color }};">Revisar ya.</strong> El consumo normal es de unas 110 consultas por día y el presupuesto acordado es de 8.000 por mes. A las 800 diarias el CRM deja de hacer las consultas no esenciales.
                    @else
                        El consumo normal es de unas 110 consultas por día. Conviene revisar qué lo está subiendo antes de llegar a las 500.
                    @endif
                </p>

                <p style="font-size:14px;">Consultas del mes: <strong>{{ number_format($month, 0, ',', '.') }}</strong> de 10.000 contratadas.</p>

                @if ($sources)
                    <p style="font-size:14px;margin-bottom:4px;"><strong>De dónde salen (hoy):</strong></p>
                    <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                        @foreach (array_slice($sources, 0, 8, true) as $source => $n)
                            <tr style="border-bottom:1px solid #E5E7EB;">
                                <td>{{ $source }}</td>
                                <td align="right"><strong>{{ $n }}</strong></td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                <p style="font-size:12px;color:#6B7280;margin-top:24px;">
                    Detalle en el servidor: <code>php artisan smd:usage</code>. Este aviso se envía una sola vez por día.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
