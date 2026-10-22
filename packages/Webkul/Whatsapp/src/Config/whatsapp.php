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
    | Mensaje de bienvenida
    |--------------------------------------------------------------------------
    |
    | Se manda al cargar un contacto nuevo desde el Punto.
    |
    | Apagado por defecto, y no es exceso de cautela: el saludo va a un número con el
    | que nunca hablamos, así que cae fuera de la ventana de 24 h de WhatsApp y EXIGE
    | una plantilla aprobada por Meta. Mientras no exista, cada alta de contacto
    | generaría un rechazo en el log sin que nadie se enterara de por qué.
    |
    | Para encenderlo: crear la plantilla en Meta (categoría Utility, con una variable
    | para el nombre), esperar la aprobación, y poner WHATSAPP_BIENVENIDA=true.
    |
    */
    'bienvenida' => [
        'enabled'  => (bool) env('WHATSAPP_BIENVENIDA', false),
        'template' => env('WHATSAPP_BIENVENIDA_TEMPLATE', 'bienvenida_sensia'),
        'language' => env('WHATSAPP_BIENVENIDA_LANG', 'es'),

        /*
        | El logo que acompaña al saludo.
        |
        | 🔴 NO es una decisión de este archivo si se ve o no: la manda la PLANTILLA.
        | El logo viaja como encabezado, y Meta valida el mensaje contra la estructura
        | exacta con la que la plantilla fue aprobada. Los dos desajustes posibles dan
        | el mismo error:
        |
        |   plantilla CON header IMAGE + envío sin imagen  → (#132012)
        |   plantilla SIN header        + envío con imagen → (#132012)
        |
        | Así que para que el logo salga hay que hacer las dos cosas:
        |
        |   1. En Meta, crear una plantilla NUEVA con encabezado de tipo Imagen.
        |
        |      Nueva y no editar `bienvenida_sensia`: al editar una plantilla aprobada
        |      Meta la pasa a revisión y mientras tanto el saludo no sale, y si la
        |      rechazan no queda la anterior. Con una nueva, la que funciona sigue
        |      funcionando y el cambio es apuntar WHATSAPP_BIENVENIDA_TEMPLATE a la
        |      nueva cuando esté aprobada — y volver atrás es la misma variable.
        |
        |   2. Poner la URL del logo acá. Mientras la plantilla apuntada no declare un
        |      header de media, esta URL se IGNORA a propósito: mandarla antes rompería
        |      el saludo que hoy sale bien.
        |
        | La URL la descarga META desde sus servidores, no el celular del cliente:
        | tiene que ser HTTPS pública, sin login y sin VPN. Un archivo en `public/`
        | servido por APP_URL cumple y además queda versionado con el repo.
        |
        | Meta acepta JPEG y PNG, hasta 5 MB, y muestra el encabezado a 1.91:1. Lo que
        | no respeta esa proporción sale recortado o con zoom.
        |
        | El archivo versionado es `public/whatsapp/bienvenida-sensia.jpg`: el logo
        | cuadrado de la marca recortado a su banda central, que es donde vive el texto
        | —el cuadrado tiene aire arriba y abajo, así que el recorte no pierde logo,
        | saca fondo—. En JPEG y no PNG porque el fondo es un degradado, que es
        | justamente lo que PNG comprime mal: 549 KB contra 65 KB, y Meta lo descarga
        | en CADA envío.
        |
        | Va en `public/` y no en `storage/`: ahí queda versionado con el repo y no
        | depende del symlink ni de que sobreviva un redeploy del volumen.
        */
        'logo' => env('WHATSAPP_BIENVENIDA_LOGO', env('APP_URL').'/whatsapp/bienvenida-sensia.jpg'),
    ],

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

        /*
         * Plantillas aprobadas que el CRM no ofrece. `hello_world` la crea Meta
         * en toda cuenta nueva y el WhatsApp Manager no deja borrarla; mandarla
         * a un cliente es un mensaje de prueba en inglés. Separadas por coma.
         */
        'hidden' => array_filter(array_map('trim', explode(',', env('WHATSAPP_TEMPLATES_HIDDEN', 'hello_world')))),
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
     * Abrir un pedido vacío (Prospectos) cuando alguien escribe y no tiene uno
     * abierto, en su ciudad o en «Sin ciudad» (LeadOpener). El agente lo completa.
     */
    'auto_create_lead' => env('WHATSAPP_AUTO_CREATE_LEAD', true),

    'unknown_city_pipeline' => env('WHATSAPP_UNKNOWN_CITY_PIPELINE', 'Sin ciudad'),

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
        // 'Sin ciudad' es de kohlberg y no existe como pipeline en Sensia. Se
        // deja porque es inofensivo y evita una asignación falsa si algún día
        // alguien crea ese pipeline.
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
        'city_agents' => [
            /*
             * Sensia: las tres ciudades las atiende el único Encargado cargado.
             *
             * El mapa va explícito, al revés de kohlberg, porque acá la
             * deducción por dueño de leads NO alcanza: Palpalá tiene su lead a
             * nombre de un Administrador (rol excluido a propósito) y Barrio
             * Alto Comedero no tiene ninguno. Dos de tres ciudades quedaban sin
             * asesor, y sin asesor la derivación no notifica a nadie.
             *
             * El argumento que dejó este mapa vacío en kohlberg —a mano se
             * desactualiza— no aplica con 3 ciudades y 1 asesor. Cuando entren
             * más Encargados HAY QUE repartirlo.
             *
             *   1 Palpalá · 3 Barrio Alto Comedero · 4 San Salvador de Jujuy
             *   11 Gustavo Ortiz <gortiz@sensia.com.ar>, rol Encargado
             */
            1 => 11,
            3 => 11,
            4 => 11,
        ],

        // A quién avisar cuando ninguna ciudad resuelve y la conversación queda
        // en el pool. Vacío = no se manda mail de pool.
        'pool_email' => env('WHATSAPP_HANDOFF_POOL_EMAIL', 'gortiz@sensia.com.ar'),
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

        /*
         * Con el agente apagado en una conversación igual se le avisa de cada
         * mensaje —los del cliente y los del asesor— con `responder: false`,
         * para que tenga el contexto cuando lo vuelvan a prender. Si intenta
         * contestar mientras está apagado, el CRM lo rechaza con 409.
         */
        'context_when_off' => (bool) env('WHATSAPP_AGENT_CONTEXT_WHEN_OFF', true),

        /*
         * Números que tienen el agente aunque esté apagado para todos, separados por
         * coma (con o sin código de país). Se suman a los de Configuración → WhatsApp
         * → Agente de IA.
         */
        'numeros' => env('WHATSAPP_AI_NUMEROS', ''),
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

    /*
    |--------------------------------------------------------------------------
    | Links de mapa
    |--------------------------------------------------------------------------
    | Los clientes mandan tanto el pin 📍 como un `maps.app.goo.gl` pegado en el
    | chat. El segundo hay que ir a resolverlo: es una redirección y las
    | coordenadas están en la URL larga.
    |
    | El timeout es corto a propósito. Con QUEUE_CONNECTION=sync esto corre
    | dentro del request del webhook de Meta, que espera un 200 rápido; si
    | Google no contesta, es mejor perder la resolución que el mensaje.
    */
    'map_links' => [
        'enabled' => env('WHATSAPP_MAP_LINKS_ENABLED', true),
        'timeout' => env('WHATSAPP_MAP_LINKS_TIMEOUT', 6),
    ],
];
