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

    // La cuenta de WhatsApp Business, no el número: el catálogo de plantillas
    // cuelga de la WABA, mientras que el envío cuelga del phone_number_id.
    'waba_id'         => env('WHATSAPP_WABA_ID'),
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
    | Queue
    |--------------------------------------------------------------------------
    | Dedicated queue (Redis) for inbound ingestion and outbound sending jobs.
    */
    'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),

    /*
    |--------------------------------------------------------------------------
    | Template catalogue
    |--------------------------------------------------------------------------
    | Approved templates are read from Meta and cached: the list barely changes
    | and the inbox asks for it every time a conversation is opened. Refresh on
    | demand with `php artisan whatsapp:templates --fresh`.
    */
    'templates' => [
        'cache_minutes' => (int) env('WHATSAPP_TEMPLATES_CACHE_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Saved locations
    |--------------------------------------------------------------------------
    | Addresses the team sends over and over (sucursal, depósito, punto de
    | entrega). They show as one-click buttons in the composer's location panel;
    | the advisor can still drag the pin or type coordinates for anything else.
    */
    'locations' => [
        // [
        //     'name'      => 'Sucursal Santa Cruz',
        //     'address'   => 'Av. Ejemplo 123, Santa Cruz',
        //     'latitude'  => -17.783330,
        //     'longitude' => -63.182140,
        // ],
    ],

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
    | Pedido automático
    |--------------------------------------------------------------------------
    | Abrir un pedido cuando escribe alguien que no tiene ninguno. Sin esto, el
    | contacto queda registrado pero no aparece en ningún pipeline: nadie lo ve,
    | nadie lo sigue, y no entra en ninguna métrica. Estaban así 156 de 168
    | conversaciones.
    |
    | El pedido se abre en la CIUDAD del contacto (que acá es el pipeline) y a
    | nombre de su encargado; si no se conoce la ciudad, en el pipeline por
    | defecto. Apagado = queda en null para que lo cree una persona desde el
    | panel del cliente.
    */
    'auto_create_lead' => env('WHATSAPP_AUTO_CREATE_LEAD', true),

    /*
    | Dónde cae el pedido cuando NO se conoce la ciudad del contacto.
    |
    | Va al pipeline "Sin ciudad" y NO al pipeline por defecto (Tarija), aunque
    | lo segundo parezca más útil. El motivo no es estético:
    |
    | CityRouter trata "el pipeline del lead" como la fuente MÁS FUERTE de la
    | ciudad —por encima de lo que el cliente le diga al agente— porque asume
    | que lo puso una persona del equipo. Un relleno automático en Tarija haría
    | que un cliente de Santa Cruz quedara marcado como de Tarija para siempre,
    | y que su propia respuesta al agente no pudiera corregirlo.
    |
    | "Sin ciudad" está en exclude_cities, así que la cascada lo ignora y la
    | ciudad real sigue pudiendo resolverse. El pedido existe y se ve en el
    | tablero; simplemente no miente sobre de dónde es.
    */
    'unknown_city_pipeline' => env('WHATSAPP_UNKNOWN_CITY_PIPELINE', 'Sin ciudad'),

    /*
    |--------------------------------------------------------------------------
    | Reactivación automática del agente
    |--------------------------------------------------------------------------
    |
    | Horas que el agente puede quedarse apagado en una conversación antes de
    | volver solo. Cuenta desde que se apagó —por el interruptor del inbox o por
    | una derivación—, no desde el último mensaje: el plazo no se corre porque
    | el asesor siga escribiendo.
    |
    | Al medirlo había 100 conversaciones con el bot apagado, 98 de ellas sin
    | ninguna derivación abierta: alguien lo apagó y nadie se acordó de
    | prenderlo. Entre ellas, una clienta que había pedido una promo.
    |
    | Reactivar CIERRA también la derivación abierta, si la hay: sin eso el
    | agente seguiría callado por handoffOpen() aunque el flag diga que sí.
    |
    | En 0 queda desactivado y ninguna conversación se reactiva sola.
    */
    'reactivate_after_hours' => (int) env('WHATSAPP_AI_REACTIVATE_HOURS', 6),

    /*
    | Quién queda como dueño de esos pedidos sin ciudad. Vacío = sin dueño.
    | Es el nombre exacto del usuario, para no clavar un id que cambia entre
    | instalaciones.
    */
    'unknown_city_owner' => env('WHATSAPP_UNKNOWN_CITY_OWNER', 'Daniel Escalante'),

    /*
    |--------------------------------------------------------------------------
    | Handoff (derivación a un asesor)
    |--------------------------------------------------------------------------
    | En kohlberg la ciudad ES el pipeline, y cada ciudad tiene un Encargado que
    | la atiende. Por eso la derivación rutea por ciudad y no por dueño del lead.
    */
    'handoff' => [
        'notify' => env('WHATSAPP_HANDOFF_NOTIFY', true),

        // El rol que atiende WhatsApp. Todo lo demás (Administrador,
        // Supervisores, y la cuenta Sistema que es el bot) queda afuera: una
        // derivación que cae en alguien que no abre WhatsApp muere en silencio.
        'advisor_roles' => ['Encargado'],

        // Pipelines que existen pero no son una ciudad real. Rutear ahí sería
        // una asignación falsa.
        'exclude_cities' => ['Sin ciudad'],

        /*
        | Mapa explícito ciudad => usuario, cuando haga falta fijar una
        | asignación. Vacío = se deduce de quién tiene los leads de esa ciudad,
        | que es como está organizado el equipo hoy:
        |
        |   Tarija 14 · Santa Cruz 9 · La Paz 4 · Cochabamba 15
        |   Sucre 7 · Potosí 8 · Oruro 3
        |
        | Se deja vacío a propósito: un mapa a mano se desactualiza en cuanto
        | alguien entra o sale del equipo, y nadie se acuerda de tocarlo.
        */
        'city_agents' => [],

        // A quién avisar cuando ninguna ciudad resuelve y la conversación queda
        // en el pool. Vacío = no se manda mail de pool.
        'pool_email' => env('WHATSAPP_HANDOFF_POOL_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mensajes interactivos (botones y listas)
    |--------------------------------------------------------------------------
    | El agente no aprende una API nueva: numera las opciones con un marcador y
    | el CRM las convierte en botones o lista según cuántas sean.
    |
    | El marcador es ".-)" —"1.-) Santa Cruz"— y no el "1." de siempre, porque
    | tiene que ser inequívoco: de los mensajes con lista numerada que hay en el
    | historial, la mitad son menús y la mitad son enumeraciones informativas
    | ("tenés 4 pedidos: 1. ... 2. ..."). Convertir esas segundas le pediría al
    | cliente que elija algo que nadie le ofreció. Medido: ".-)" no aparece ni
    | una vez en 457 mensajes reales, entrantes y salientes.
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
    | Seguimiento y cierre por falta de respuesta
    |--------------------------------------------------------------------------
    | Si le escribimos al cliente y no contesta: a las 16 h de su último
    | mensaje (con 8 h de ventana todavía abierta) Sofía le manda un
    | recordatorio de texto, y a las 72 h de nuestro último mensaje el pedido
    | pasa a "Cliente sin interés" en su propio pipeline. Si vuelve a escribir,
    | se reabre. Spec: tasks/spec-sin-respuesta-3-dias.md.
    |
    | Texto y no plantilla a propósito: las plantillas de reapertura tuvieron
    | 0 respuestas de 36 en 30 días (medido el 15/10/2026).
    |
    | `since` vacío = todo apagado. Es también lo que hace que la regla corra
    | sólo hacia adelante: los mensajes anteriores a esa fecha no cuentan, así
    | que los pedidos que ya estaban parados al activarla no se cierran de golpe.
    */
    'auto_close' => [
        'since'          => env('WHATSAPP_AUTO_CLOSE_SINCE'),
        'followup_after' => (int) env('WHATSAPP_FOLLOWUP_HOURS', 16),
        'close_after'    => (int) env('WHATSAPP_AUTO_CLOSE_HOURS', 72),
        'user_id'        => (int) env('WHATSAPP_AUTO_CLOSE_USER_ID', 13), // usuario Sistema ("Agente")
        'stages'         => ['no-atendido', 'cliente-contactado'],
        'closed_stage'   => 'cliente-sin-inters',
        'reopen_stage'   => 'cliente-contactado',
        // Neutro a propósito: no supone de qué se habló. Nunca en mayúsculas.
        'followup_text'  => 'Hola{nombre} 👋 ¿Te puedo ayudar en algo más? Cuando quieras, seguimos por aquí 🍷',
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
    | Front-end
    |--------------------------------------------------------------------------
    | While the backend endpoints are being built (Sprints 0-2) the inbox
    | renders mock data so the UI can be reviewed. Flip to false once the real
    | endpoints/broadcasting are wired.
    */
    'ui' => [
        'use_mock' => env('WHATSAPP_UI_USE_MOCK', false),

        /*
         * Símbolo de la moneda del CRM (config('app.currency')) para las
         * monedas cuyo símbolo corto ICU no conoce: NumberFormatter devuelve
         * "BOB" y no "Bs". Lo que no esté acá lo resuelve ICU.
         */
        'currency_symbols' => [
            'BOB' => 'Bs',
        ],
    ],
];
