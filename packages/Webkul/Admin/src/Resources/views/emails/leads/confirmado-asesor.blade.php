{{--
    Aviso al asesor cuando su pedido pasa a "Cliente confirmado".

    Maquetado con tablas y estilos en línea a propósito: Gmail y Outlook
    descartan <style> en el <head> y no soportan flex ni grid, así que un
    layout "moderno" llegaría desarmado. El ancho fijo de 570 px es el que
    entra sin scroll horizontal en el panel de lectura de Outlook.
--}}
<table style="width: 570px; border-collapse: collapse;" width="100%" cellspacing="0" cellpadding="0">
    <tbody>
        <tr>
            <td style="padding: 24px 0; text-align: center; background-color: #ffffff;" bgcolor="#FFFFFF">
                <a href="https://sofopolis.com/">
                    <img style="max-width: 180px; border: 0; text-decoration: none;" src="https://sofopolis.com/wp-content/uploads/2026/02/logo-sofopolis.png" alt="Logo" width="180" />
                </a>
            </td>
        </tr>
    </tbody>
</table>

<table style="width: 100%; border-collapse: collapse;" width="100%" cellspacing="0" cellpadding="0">
    <tbody>
        <tr>
            <td style="padding: 0; margin: 0;" align="center">
                <table style="width: 570px; max-width: 570px; border-collapse: collapse; background-color: #ffffff;" width="570" cellspacing="0" cellpadding="0" bgcolor="#FFFFFF">
                    <tbody>
                        <tr>
                            <td style="padding: 36px;">

                                <h1 style="font-size: 22px; font-weight: bold; color: #111827; margin: 0 0 16px 0; letter-spacing: -0.3px;">
                                    Nuevo pedido confirmado
                                </h1>

                                <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">
                                    El pedido pasó a <strong>{{ $etapa }}</strong>. A continuación se detallan los datos capturados:
                                </p>

                                <div style="background-color: #f8faf5; border: 1px solid #E5E7EB; border-left: 4px solid #D41E35; padding: 16px; margin: 24px 0; border-radius: 6px;">

                                    <div style="margin-bottom: 8px; font-size: 14px;">
                                        <strong style="color: #111827;">Nombre:</strong> {{ $nombre }}
                                    </div>

                                    <div style="margin-bottom: 8px; font-size: 14px;">
                                        <strong style="color: #111827;">Teléfono:</strong> {{ $telefono }}
                                    </div>

                                    <div style="margin-bottom: 8px; font-size: 14px;">
                                        <strong style="color: #111827;">ID del Pedido:</strong> {{ $lead->id }}
                                    </div>

                                    <div style="margin-bottom: 8px; font-size: 14px;">
                                        <strong style="color: #111827;">Ciudad:</strong> {{ $ciudad }}
                                    </div>

                                    <div style="margin-bottom: 0; font-size: 14px;">
                                        <strong style="color: #111827;">Productos:</strong>
                                        @forelse ($productos as $producto)
                                            <br />&nbsp;&nbsp;&bull; {{ $producto }}
                                        @empty
                                            <br />&nbsp;&nbsp;&bull; <em>sin productos cargados</em>
                                        @endforelse
                                    </div>
                                </div>

                                <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">
                                    Por favor, revisar este registro y dar seguimiento según el flujo comercial correspondiente.
                                </p>

                                <p style="font-size: 15px; line-height: 1.6; margin: 32px 0 0 0;">
                                    Saludos,<br />
                                    <strong style="color: #111827;">Sistema de Notificaciones</strong>
                                </p>

                            </td>
                        </tr>
                    </tbody>
                </table>

                <table style="width: 570px; max-width: 570px; border-collapse: collapse; text-align: center;" width="570" cellspacing="0" cellpadding="0">
                    <tbody>
                        <tr>
                            <td style="padding: 24px 0 0 0;">
                                <p style="font-size: 13px; line-height: 1.4; color: #6b6e76; margin: 0 0 8px 0;">Notificación automática del sistema</p>
                                <p style="font-size: 13px; line-height: 1.4; color: #6b6e76; margin: 0;">&copy; {{ date('Y') }} Sofopolis</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

            </td>
        </tr>
    </tbody>
</table>
