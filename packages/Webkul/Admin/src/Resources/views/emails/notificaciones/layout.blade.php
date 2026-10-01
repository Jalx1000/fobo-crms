{{--
    Marco común de los correos de notificación: logo, tarjeta blanca y pie.
    Tablas y estilos en línea a propósito: es lo único que Gmail y Outlook
    respetan de forma pareja.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #374151;">
<table style="width: 100%; border-collapse: collapse; background-color: #f3f4f6;" width="100%" cellspacing="0" cellpadding="0" bgcolor="#F3F4F6">
<tbody>
<tr>
<td style="padding: 0 12px;" align="center">

    <table style="width: 570px; max-width: 100%; border-collapse: collapse;" width="570" cellspacing="0" cellpadding="0">
    <tbody>
    <tr>
    <td style="padding: 24px 0; text-align: center;">
        <a href="https://imprimir.com.bo/">
            @php
                /*
                 * El logo va INCRUSTADO en el correo (CID), no enlazado: Gmail
                 * oculta las imágenes externas de remitentes nuevos o en spam,
                 * y una incrustada viaja con el mensaje. $message solo existe
                 * al enviar; al renderizar (vista previa, tests) se usa la URL.
                 */
                $logo = isset($message)
                    ? $message->embed(base_path('packages/Webkul/Admin/src/Resources/assets/images/emails/logo-imprimir.png'))
                    : 'https://imprimir.com.bo/wp-content/uploads/2023/10/logo-con-texto-imprimir-300x203.png';
            @endphp
            <img style="max-width: 180px; border: 0; text-decoration: none;" src="{{ $logo }}" alt="Imprimir" width="180" />
        </a>
    </td>
    </tr>
    </tbody>
    </table>

    <table style="width: 570px; max-width: 100%; border-collapse: collapse; background-color: #ffffff;" width="570" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF">
    <tbody>
    <tr>
    <td style="padding: 36px;">

        <h1 style="font-size: 22px; font-weight: bold; color: #111827; margin: 0 0 16px 0; letter-spacing: -0.3px;">
            @yield('titulo')
        </h1>

        @yield('contenido')

        <p style="font-size: 15px; line-height: 1.6; margin: 32px 0 0 0;">
            Saludos,<br />
            <strong style="color: #111827;">@yield('firma', 'Sistema de Notificaciones')</strong>
        </p>

    </td>
    </tr>
    </tbody>
    </table>

    <table style="width: 570px; max-width: 100%; border-collapse: collapse; text-align: center;" width="570" cellspacing="0" cellpadding="0">
    <tbody>
    <tr>
    <td style="padding: 24px 0;">
        <p style="font-size: 13px; line-height: 1.4; color: #6b6e76; margin: 0 0 8px 0;">@yield('pie', 'Notificación automática del sistema')</p>
        <p style="font-size: 13px; line-height: 1.4; color: #6b6e76; margin: 0;">&copy; {{ date('Y') }} Imprimir</p>
    </td>
    </tr>
    </tbody>
    </table>

</td>
</tr>
</tbody>
</table>
</body>
</html>
