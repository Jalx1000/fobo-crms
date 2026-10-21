<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API credentials
|--------------------------------------------------------------------------
| Declared once and reused below, so the legacy 'cloud' key (still read by the
| inbound webhook) and the gateway registry never drift apart.
*/
$cloud = [
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'token'           => env('WHATSAPP_TOKEN'),
    'app_secret'      => env('WHATSAPP_APP_SECRET'),
    'verify_token'    => env('WHATSAPP_VERIFY_TOKEN'),
    'api_version'     => env('WHATSAPP_API_VERSION', 'v21.0'),
];

return [
    /*
    |--------------------------------------------------------------------------
    | Active gateway
    |--------------------------------------------------------------------------
    | One provider per installation. Switching providers = change this + redeploy.
    */
    'gateway' => env('WHATSAPP_GATEWAY', 'cloud_api'),

    /*
    |--------------------------------------------------------------------------
    | Gateway registry
    |--------------------------------------------------------------------------
    | Each driver receives its own config array. The core never reads these
    | keys directly — only the driver does.
    */
    'gateways' => [
        'cloud_api' => array_merge($cloud, [
            'driver' => \Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway::class,
        ]),

        'kommo' => [
            'driver'    => \Webkul\Whatsapp\Gateways\Kommo\KommoGateway::class,
            'subdomain' => env('KOMMO_SUBDOMAIN'),
            'token'     => env('KOMMO_TOKEN'),

            /*
            | MODO MIXTO: Kommo recibe, otro gateway envía.
            |
            | El salesbot de Kommo solo sabe relevar texto: ni plantillas, ni
            | archivos, ni ubicación, ni botones. Delegando el envío en Cloud API
            | la conversación gana todo eso sin cambiar de dónde entra.
            |
            | Con 'kommo' (o vacío) vuelve el envío por salesbot, que es la
            | marcha atrás sin deploy.
            |
            | SUPUESTO BLOQUEANTE: el número de Cloud API debe ser EL MISMO que
            | está conectado a Kommo. Si no, el cliente escribe a un número y le
            | contesta otro, y la ventana de 24 h no aplica.
            */
            'send_via' => env('KOMMO_SEND_VIA', 'cloud_api'),

            // Kommo signs nothing, so this secret in the webhook URL is the
            // only thing standing between the inbox and forged messages.
            // Generate with: php artisan whatsapp:webhook-info
            'webhook_secret' => env('KOMMO_WEBHOOK_SECRET'),

            // Accept every webhook, authenticated or not. Anyone who learns the
            // URL can then forge inbound messages. Opt-in on purpose, and
            // surfaced by `php artisan whatsapp:webhook-info`.
            'allow_unauthenticated' => env('KOMMO_WEBHOOK_INSECURE', false),

            // Custom field on the lead where the outgoing text is parked...
            'message_field_id' => env('KOMMO_MESSAGE_FIELD_ID'),

            // ...and the salesbot that reads it and delivers the message.
            'bot_id'      => env('KOMMO_BOT_ID'),
            'entity_type' => env('KOMMO_ENTITY_TYPE', 2), // 2 = lead
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (legacy key)
    |--------------------------------------------------------------------------
    | Still read by the inbound webhook until the inbound half moves behind the
    | gateway contract. Same values as gateways.cloud_api.
    */
    'cloud' => $cloud,

    /*
    |--------------------------------------------------------------------------
    | Media entrante
    |--------------------------------------------------------------------------
    | Qué se descarga de lo que manda el cliente, y hasta qué tamaño.
    |
    | Los límites se comprueban contra los metadatos ANTES de bajar el binario:
    | no tiene sentido traer 90 MB para después descartarlos. El de video es más
    | alto porque un video legítimo pesa lo que pesa; el resto comparte un tope
    | que cubre cualquier foto o PDF sin llenar el disco.
    |
    | Los archivos se conservan indefinidamente (no hay purga). Conviene medir
    | `storage/app/whatsapp` de vez en cuando: los audios de voz son el rubro
    | que más rápido se acumula.
    */
    'inbound_media' => [
        'tipos' => ['image', 'video', 'audio', 'document', 'sticker'],

        'max_bytes' => [
            'video'   => 100 * 1024 * 1024,
            'default' => 25 * 1024 * 1024,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Dedicated queue (Redis) for inbound ingestion and outbound sending jobs.
    */
    'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),

    /*
    |--------------------------------------------------------------------------
    | Unknown contacts
    |--------------------------------------------------------------------------
    | Register a Person automatically when someone writes from a number that is
    | not in the CRM. Off means the conversation stays "unassigned" until a human
    | links it.
    */
    'auto_create_person' => env('WHATSAPP_AUTO_CREATE_PERSON', true),

    /*
    |--------------------------------------------------------------------------
    | Recontacto antes de cerrarse la ventana de 24 h
    |--------------------------------------------------------------------------
    | Cuando el cliente deja de responder, la ventana de texto libre se va
    | cerrando. Una hora antes de que expire se le manda un recordatorio en voz
    | de Sofía, que es la última chance de reenganchar sin plantilla —y sin el
    | costo de una.
    |
    | `horas_antes` es cuánto antes del cierre se dispara. Una hora da margen a
    | que el cliente lea y conteste todavía dentro de la ventana; quince minutos
    | no, y tres horas interrumpe a alguien que quizá seguía pensándolo.
    */
    'followup' => [
        'enabled' => env('WHATSAPP_FOLLOWUP_ENABLED', true),

        'horas_antes' => (float) env('WHATSAPP_FOLLOWUP_HORAS_ANTES', 1),

        /*
        | El texto, con {nombre} opcional.
        |
        | Si la conversación no tiene nombre, el saludo se recorta a «Hola 👋»
        | en vez de dejar «Hola  👋» con un hueco o, peor, «Hola {nombre}».
        */
        'mensaje' => env(
            'WHATSAPP_FOLLOWUP_MENSAJE',
            'Hola {nombre} 👋 Quedé a la orden para ayudarle con su departamento en Ponce de León. '
            .'¿Seguimos? Si quiere, coordinamos una visita para que lo conozca en persona.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quién ve las conversaciones sin contacto ni lead
    |--------------------------------------------------------------------------
    | Números que todavía no son de nadie (filtro «Sin asignar»). Los ven solo
    | estos usuarios, que son quienes las asignan; el resto, incluidos los
    | supervisores, ve únicamente conversaciones de leads. Hoy: Sofopolis (1) y
    | Danitza (3). Spec tablero-por-asesor, D11.
    */
    'ven_sin_asignar_user_ids' => array_map('intval', array_filter(explode(',', (string) env(
        'WHATSAPP_VEN_SIN_ASIGNAR',
        '1,3'
    )))),

    /*
    |--------------------------------------------------------------------------
    | Plantillas aprobadas por Meta
    |--------------------------------------------------------------------------
    | Las únicas que se pueden mandar fuera de la ventana de 24 h.
    |
    | EL TEXTO SE COPIA ACÁ AUNQUE META YA LO TENGA. Al enviar, Meta solo recibe
    | el nombre y los parámetros; sin el texto, el inbox mostraría «se mandó
    | seguimiento_solicitud» y el asesor no sabría qué leyó el cliente. Si se
    | edita una plantilla en Meta, hay que actualizarla acá también.
    |
    | `body` usa los marcadores de Meta ({{1}}, {{2}}...) y se completa con los
    | parámetros en el mismo orden. Una plantilla que no está acá no se puede
    | enviar desde el CRM, a propósito: es la lista de lo que Meta ya aprobó.
    */
    'templates' => [
        'seguimiento_solicitud' => [
            'language' => 'es',
            'category' => 'UTILITY',
            'body'     => "Hola {{1}}, le escribimos de Ponce de León para dar seguimiento a la consulta que nos realizó.\n\n¿Desea que continuemos con su solicitud?",
            'buttons'  => ['Sí, continuar', 'Ya no me interesa'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Menús tocables desde texto numerado
    |--------------------------------------------------------------------------
    | El agente no necesita una tool nueva para mandar botones: numera las
    | opciones con el marcador y el CRM las convierte.
    |
    | EL MARCADOR ES ".-)" Y NO "1." A SECAS, A PROPÓSITO. Medido sobre el
    | historial de producción de Sensia: de 4 mensajes con lista numerada, 2 eran
    | menús y 2 eran enumeraciones informativas ("tenés 4 pedidos: 1. Pedido
    | de..."). Detectar "1." da un 50 % de falsos positivos, y convertir una
    | enumeración en botones le pide al cliente que elija algo que nadie le
    | ofreció. ".-)" no aparece ni una vez en 457 mensajes reales.
    */
    'interactive' => [
        'enabled' => env('WHATSAPP_INTERACTIVE_ENABLED', true),

        // Lo que va entre el número y el texto de la opción.
        'marker' => env('WHATSAPP_INTERACTIVE_MARKER', '.-)'),

        // El label del botón que abre una lista (máx. 20 caracteres).
        'list_button' => env('WHATSAPP_INTERACTIVE_LIST_BUTTON', 'Ver opciones'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Agent
    |--------------------------------------------------------------------------
    | Global default for the AI agent switch and the outbound webhook the
    | external agent (n8n or other) is notified on. Overridable per conversation.
    */
    'ai' => [
        'enabled'      => env('WHATSAPP_AI_ENABLED', false),
        'webhook_url'  => env('WHATSAPP_AGENT_WEBHOOK_URL'),
        'token'        => env('WHATSAPP_AGENT_TOKEN'), // Bearer the CRM sends so the agent knows it's us
        'history_size' => env('WHATSAPP_AGENT_HISTORY_SIZE', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Derivación a un asesor
    |--------------------------------------------------------------------------
    | Al cliente no se le avisa nada cuando la conversación pasa a una persona:
    | la derivación es interna. Por eso el mail al asesor es la señal de
    | respaldo — la primaria es el chip "Sin asesor" del inbox, que no depende
    | de que un correo llegue.
    |
    | La dirección sale del propio usuario del CRM, la misma con la que entra al
    | sistema, así que no hay una segunda lista que mantener sincronizada.
    */
    'handoff' => [
        'notify' => env('WHATSAPP_HANDOFF_NOTIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Front-end
    |--------------------------------------------------------------------------
    | While the backend endpoints are being built (Sprints 0-2) the inbox
    | renders mock data so the UI can be reviewed. Flip to false once the real
    | endpoints/broadcasting are wired.
    */
    'ui' => [
        'use_mock' => env('WHATSAPP_UI_USE_MOCK', false),
    ],
];
