<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { width: 80%; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .header { font-size: 18px; font-weight: bold; border-bottom: 2px solid #BA2831; padding-bottom: 10px; margin-bottom: 20px; color: #BA2831; }
        .detail-row { margin-bottom: 10px; }
        .label { font-weight: bold; width: 150px; display: inline-block; }
        .button { display: inline-block; margin-top: 15px; padding: 10px 18px; background: #BA2831; color: #fff !important; text-decoration: none; border-radius: 4px; }
        .footer { margin-top: 30px; font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            Nuevo contacto registrado
        </div>

        <p>Hola {{ $asesor->name }}, se registró un contacto que te corresponde:</p>

        <div class="detail-row">
            <span class="label">Nombre:</span> {{ $person->name }}
        </div>
        <div class="detail-row">
            <span class="label">Ciudad:</span> {{ $ciudad ?? 'Sin ciudad' }}
        </div>
        <div class="detail-row">
            <span class="label">Teléfono:</span> {{ collect($person->contact_numbers ?? [])->pluck('value')->filter()->implode(', ') ?: '—' }}
        </div>
        <div class="detail-row">
            <span class="label">Email:</span> {{ collect($person->emails ?? [])->pluck('value')->filter()->implode(', ') ?: '—' }}
        </div>

        <a class="button" href="{{ route('admin.contacts.persons.view', $person->id) }}">Ver contacto en el CRM</a>

        <div class="footer">
            {{ config('app.name') }} · Aviso automático del CRM.
        </div>
    </div>
</body>
</html>
