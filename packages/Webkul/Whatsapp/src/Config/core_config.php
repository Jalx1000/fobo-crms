<?php

return [
    [
        'key'  => 'whatsapp',
        'name' => 'WhatsApp',
        'info' => 'La bandeja de WhatsApp y el agente de IA.',
        'icon' => 'icon-message',
        'sort' => 7,
    ], [
        'key'    => 'whatsapp.derivaciones',
        'name'   => 'Derivaciones a un asesor',
        'info'   => 'Una derivación abierta deja la conversación sin el agente de IA hasta que '
            .'alguien la cierre. Si nadie la cierra, el cliente queda sin respuesta: por eso '
            .'se cierran solas pasado un tiempo.',
        'sort'   => 1,
        'fields' => [
            [
                'name'    => 'cierre_automatico',
                'title'   => 'Cerrar solas las derivaciones',
                'type'    => 'boolean',
                'default' => true,
            ], [
                'name'       => 'horas',
                'title'      => 'Horas desde que se derivó hasta cerrarla',
                'type'       => 'number',
                'validation' => 'required|integer|min:1|max:720',
                'default'    => 12,
            ], [
                'name'    => 'devolver_al_agente',
                'title'   => 'Al cerrarla, devolver la conversación al agente de IA',
                'type'    => 'boolean',
                'default' => true,
            ],
        ],
    ],
];
