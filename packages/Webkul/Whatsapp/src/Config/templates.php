<?php

/*
|--------------------------------------------------------------------------
| Plantillas de WhatsApp para dar de alta en Meta
|--------------------------------------------------------------------------
| `php artisan whatsapp:templates-create` las valida y las crea en la cuenta
| de WhatsApp Business (Cloud API). Meta las revisa: quedan PENDING y recién
| aprobadas aparecen en el chat (`php artisan whatsapp:templates --fresh`).
|
| Convenciones:
| - {{1}} es siempre el nombre del paciente.
| - `examples` lleva un valor por variable, en orden: Meta los exige.
| - Una plantilla aprobada no se edita desde acá: cambiar el texto requiere
|   una nueva (otro nombre) o editarla en el Business Manager.
| - MARKETING: promociones y recontactos. UTILITY: algo que el paciente ya
|   pidió (su cita, su consulta). Meta reclasifica si no coincide.
*/

return [
    // Contacto nuevo que llegó (formulario, anuncio, SMD) y nunca escribió.
    'bienvenida_nuevo_contacto' => [
        'category'  => 'MARKETING',
        'language'  => 'es',
        'header'    => 'Clínica Odontoking',
        'body'      => "Hola {{1}}, le saluda la Clínica Odontoking. 😊\n\n"
            .'Recibimos su solicitud y queremos ayudarle a cuidar su sonrisa. '
            .'¿Le gustaría agendar una consulta de evaluación? Responda este mensaje y un asesor le atenderá.',
        'examples'  => ['María'],
        'footer'    => 'Responda «No, gracias» para no recibir más mensajes.',
        'buttons'   => ['Agendar consulta', 'Tengo una pregunta', 'No, gracias'],
    ],

    // Paciente que consultó y no agendó, o dejó de responder.
    'recontacto_paciente' => [
        'category'  => 'MARKETING',
        'language'  => 'es',
        'header'    => 'Clínica Odontoking',
        'body'      => 'Hola {{1}}, le escribimos de la Clínica Odontoking para dar seguimiento a su consulta sobre {{2}}.'
            ."\n\n¿Desea que le ayudemos a agendar su cita? Tenemos horarios disponibles esta semana.",
        'examples'  => ['María', 'ortodoncia'],
        'footer'    => 'Responda «No recibir mensajes» para darse de baja.',
        'buttons'   => ['Sí, agendar', 'Más adelante', 'No recibir mensajes'],
    ],

    // Saludo del asesor para retomar la conversación cuando pasaron más de
    // 24 h desde el último mensaje del paciente (solo se puede abrir con
    // plantilla; al responder se habilita el texto libre).
    'saludo_retomar_conversacion' => [
        'category'  => 'UTILITY',
        'language'  => 'es',
        'body'      => 'Hola {{1}}, le saluda {{2}} de la Clínica Odontoking.'
            ."\n\nLe escribimos para continuar con la atención de su consulta. Por favor, responda este mensaje y con gusto le ayudamos.",
        'examples'  => ['María', 'Ana'],
        'buttons'   => ['Continuar'],
    ],

    // Cita reprogramada desde el CRM cuando el paciente no escribió en las
    // últimas 24 h (planning/17, D9). {{2}} con el formato del recordatorio:
    // "Martes 3 de Noviembre a horas 09:30".
    'cita_reprogramada' => [
        'category'  => 'UTILITY',
        'language'  => 'es',
        'body'      => 'Hola {{1}}, le informamos que su cita en la Clínica Odontoking fue reprogramada para el {{2}} con el Dr(a). {{3}}.'
            .' Si necesita otro horario, responda a este mensaje.',
        'examples'  => ['María', 'Martes 3 de Noviembre a horas 09:30', 'Carolina Rojas'],
    ],
];
