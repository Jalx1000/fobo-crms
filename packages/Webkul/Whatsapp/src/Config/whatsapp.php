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
    // La cuenta (WABA) dueña de ese numero. Solo hace falta para LEER el
    // catalogo de plantillas: los envios se direccionan por phone_number_id.
    // Tiene que ser el WABA de ESE numero — un par desalineado devuelve 132001
    // o "Unsupported post request". `php artisan whatsapp:waba-id` lo descubre.
    'waba_id'         => env('WHATSAPP_WABA_ID'),
    'token'           => env('WHATSAPP_TOKEN'),
    'app_secret'      => env('WHATSAPP_APP_SECRET'),
    'verify_token'    => env('WHATSAPP_VERIFY_TOKEN'),
    'api_version'     => env('WHATSAPP_API_VERSION', 'v21.0'),
];

return [
    /*
    |--------------------------------------------------------------------------
    | Default WhatsApp provider
    |--------------------------------------------------------------------------
    | Which WhatsApp provider new outbound conversations are opened on. It does
    | NOT disable the others: every registered channel keeps receiving, and each
    | conversation is always answered through the channel it arrived on.
    */
    'gateway' => env('WHATSAPP_GATEWAY', 'cloud_api'),

    /*
    |--------------------------------------------------------------------------
    | Channel registry
    |--------------------------------------------------------------------------
    | Each driver receives its own config array. The core never reads these
    | keys directly — only the driver does. Two entries are core:
    |
    | - label:  what the inbox shows on the badge. Users never see the raw key.
    | - family: channels that address the same identity (a phone number). The
    |           list may collapse duplicates within a family — a contact
    |           migrated from Kommo to Cloud API is one person — but never
    |           across families, where the same digits mean nothing alike.
    |
    | Other modules add their own channel here from their service provider's
    | register() (see Webkul\Messenger).
    */
    'gateways' => [
        'cloud_api' => array_merge($cloud, [
            'driver'      => \Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway::class,
            'label'       => 'WhatsApp',
            'family'      => 'whatsapp',
            'lead_source' => 'WhatsApp',
        ]),

        'kommo' => [
            'driver'      => \Webkul\Whatsapp\Gateways\Kommo\KommoGateway::class,
            'label'       => 'WhatsApp (Kommo)',
            'family'      => 'whatsapp',
            // Same source as Cloud API on purpose: the customer wrote on
            // WhatsApp; which provider carried it is our plumbing, not theirs.
            'lead_source' => 'WhatsApp',
            'subdomain'   => env('KOMMO_SUBDOMAIN'),
            'token'       => env('KOMMO_TOKEN'),

            // Modo mixto: Kommo solo RECIBE y todo envío sale por este gateway
            // (normalmente 'cloud_api', el mismo número de WhatsApp). 'kommo'
            // = el salesbot de siempre, que solo sabe mandar texto. Por defecto
            // 'kommo' para que activarlo sea una decisión explícita en el .env.
            'send_via'    => env('KOMMO_SEND_VIA', 'kommo'),

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
    | Approved templates (HSM)
    |--------------------------------------------------------------------------
    | Once the 24h service window closes, WhatsApp only accepts templates that
    | Meta approved beforehand. They are created and approved by hand in
    | WhatsApp Manager — the CRM never creates them — so all we keep here is the
    | pointer.
    |
    | `language` must be the EXACT code the template was approved under. It is
    | not a preference and there is no fallback: a mismatch comes back as 132001
    | and the message is lost.
    |
    | Categoría esperada: Utility. Si Meta la reclasifica a Marketing, el envío
    | pasa a cobrarse también dentro de la ventana — revisar el texto aprobado.
    */
    'templates' => [
        // Minutos que se cachea el catalogo leido de Meta. El catalogo casi no
        // cambia y el inbox lo pide en cada conversacion.
        'cache_minutes' => env('WHATSAPP_TEMPLATES_CACHE_MINUTES', 30),

        // Cotización enviada como PDF adjunto en el header.
        // Body: {{1}} contacto, {{2}} asunto, {{3}} total, {{4}} validez.
        'quote' => [
            'name'     => env('WHATSAPP_TEMPLATE_QUOTE', 'cotizacion_documento'),
            'language' => env('WHATSAPP_TEMPLATE_QUOTE_LANG', 'es'),
        ],
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
    | Auto lead
    |--------------------------------------------------------------------------
    | Open a lead for a conversation whose contact has none yet (default pipeline,
    | first stage, "WhatsApp" source). Off keeps the conversation lead-less until
    | a human creates one from the profile drawer.
    */
    'auto_create_lead' => env('WHATSAPP_AUTO_CREATE_LEAD', true),

    /*
    |--------------------------------------------------------------------------
    | Default country code
    |--------------------------------------------------------------------------
    | Prepended to bare national numbers (no country code) so "75640698" and
    | "59175640698" resolve to the same contact instead of fragmenting into
    | separate conversations. Bolivia = 591. Digits only, no "+".
    */
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '591'),

    /*
    |--------------------------------------------------------------------------
    | Outbound media
    |--------------------------------------------------------------------------
    | Meta's own limits, mirrored here so an oversized or unsupported file is
    | rejected in the composer with a clear message instead of coming back as an
    | opaque 400 from Graph three seconds later. Sizes in kilobytes (Laravel's
    | `max:` unit). Do NOT raise these above Meta's values — the API is the one
    | enforcing them.
    |
    | https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media
    */
    'media' => [
        'image' => [
            'max_kb' => 5120,   // 5 MB
            'mimes'  => ['image/jpeg', 'image/png'],
        ],

        'audio' => [
            'max_kb' => 16384,  // 16 MB
            'mimes'  => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'],
        ],

        'video' => [
            // Only H.264 + AAC. Meta transcodes nothing: a .mp4 with another
            // codec uploads fine and then fails to play on the handset.
            'max_kb' => 16384,  // 16 MB
            'mimes'  => ['video/mp4', 'video/3gp', 'video/3gpp'],
        ],

        'document' => [
            'max_kb' => 102400, // 100 MB
            'mimes'  => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'text/csv',
            ],
        ],

        'sticker' => [
            // Static only. Animated stickers get 500 KB, but there is no way to
            // tell the two apart before uploading, so the strict limit wins.
            'max_kb' => 100,
            'mimes'  => ['image/webp'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Saved locations
    |--------------------------------------------------------------------------
    | Addresses the team sends over and over (branch, workshop, pickup point).
    | They show as one-click buttons in the composer's location panel; the
    | advisor can still type coordinates by hand for anything else.
    */
    'locations' => [
        // [
        //     'name'      => 'Sucursal Central',
        //     'address'   => 'Av. Ejemplo 123, Santa Cruz',
        //     'latitude'  => -17.783330,
        //     'longitude' => -63.182140,
        // ],
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
    | Handoff (derivación a un asesor)
    |--------------------------------------------------------------------------
    | When the AI agent (or an advisor) hands a conversation to a human, the
    | customer is told nothing — the handoff is internal. So the notification
    | below is the backup signal; the primary one is the "Sin asesor" chip in
    | the inbox list.
    |
    | Who gets the mail comes from the CRM's own users — the address they log in
    | with — so there is no second list to keep in sync with the staff:
    |
    | - assigned  → the advisor who owns it.
    | - pooled    → every active user, narrowed to a group when pool_group_id is
    |               set, minus exclude_user_ids (the API/bot account has a
    |               mailbox nobody reads).
    |
    | pool_emails only adds extra addresses on top (a shared sales mailbox).
    */
    /*
    | Minutos en los que no se reenvía la misma cotización a la misma
    | conversación (el botón «Enviar por WhatsApp» del listado). 0 = sin límite.
    */
    'quote_resend_minutes' => (int) env('WHATSAPP_QUOTE_RESEND_MINUTES', 10),

    'handoff' => [
        'notify' => env('WHATSAPP_HANDOFF_NOTIFY', true),

        // Krayin user group that covers the pool. Null = every active user.
        'pool_group_id' => env('WHATSAPP_HANDOFF_POOL_GROUP_ID'),

        // Roles that manage rather than attend. They are not the ones who have
        // to answer an unclaimed conversation, and mailing them on every one is
        // noise. Role NAMES, comma-separated.
        'exclude_roles' => array_filter(array_map(
            'trim',
            explode(',', (string) env('WHATSAPP_HANDOFF_EXCLUDE_ROLES', 'Administrador,Supervisores,Sistema'))
        )),

        // User ids never worth mailing, comma-separated.
        'exclude_user_ids' => array_filter(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_HANDOFF_EXCLUDE_USERS', ''))))
        )),

        'pool_emails' => array_filter(array_map(
            'trim',
            explode(',', (string) env('WHATSAPP_HANDOFF_POOL_EMAILS', ''))
        )),
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

    /*
    |--------------------------------------------------------------------------
    | Ventana anti-duplicado del agente
    |--------------------------------------------------------------------------
    | Segundos durante los cuales un mensaje de texto del agente que repite el
    | menú interactivo recién enviado se rechaza con 409.
    |
    | El modelo tiende a llamar a `mostrar_opciones` y ADEMÁS escribir la misma
    | pregunta como texto: el cliente la recibe dos veces, una con botones y otra
    | sin ellos. En 0 se desactiva la defensa.
    */
    'antiduplicado_segundos' => env('WHATSAPP_ANTIDUPLICADO_SEGUNDOS', 120),

];
