<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { width: 80%; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .header { font-size: 18px; font-weight: bold; border-bottom: 2px solid #BA2831; padding-bottom: 10px; margin-bottom: 20px; color: #BA2831; }
        .footer { margin-top: 30px; font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            ¡Hola, {{ $person->name }}!
        </div>

        <p>Gracias por contactarte con <strong>{{ config('app.name') }}</strong>. Ya registramos tus datos y un asesor te va a atender a la brevedad.</p>

        <p>Si querés adelantar tu consulta, podés responder este correo o escribirnos por WhatsApp.</p>

        <div class="footer">
            {{ config('app.name') }} · Este es un mensaje automático.
        </div>
    </div>
</body>
</html>
