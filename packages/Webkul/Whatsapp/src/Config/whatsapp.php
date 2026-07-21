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

    // WhatsApp Business Account that owns the phone number. Templates live on
    // the account, not the number, so reading the catalogue needs it — and it
    // cannot be derived from the token or the phone number, it has to be copied
    // from WhatsApp Manager.
    'waba_id'         => env('WHATSAPP_WABA_ID'),
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
    | Outbound gateway
    |--------------------------------------------------------------------------
    | Driver used to SEND, when it differs from the one that receives. Odontoking
    | receives through Kommo but sends through the Cloud API on the same WhatsApp
    | number: talking to Meta directly gives a real message id and drops the
    | salesbot relay, which could only answer inside an existing chat.
    |
    | Applies to every outbound path — agent replies, inbox replies, reminders —
    | because it is resolved in the send job itself. Null = each conversation
    | sends through its own gateway.
    */
    'outbound_gateway' => env('WHATSAPP_OUTBOUND_GATEWAY') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Default country code
    |--------------------------------------------------------------------------
    | Prepended to numbers stored without one (the CRM holds Bolivian patient
    | phones as 8 digits, no country code). Applied before addressing a provider
    | so WhatsApp receives a full international number. Digits only, no '+'.
    */
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '591'),

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

            // Approved Meta templates (HSM) keyed the same as the other gateways,
            // so the reminder works whichever gateway sends it. `name`/`language`
            // must match exactly what was approved in Meta Business Manager.
            'templates' => [
                'appointment_reminder' => [
                    'name'     => env('WHATSAPP_REMINDER_TEMPLATE_NAME', 'recordatorio_cita'),
                    'language' => env('WHATSAPP_REMINDER_TEMPLATE_LANG', 'es'),
                ],
            ],
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

            // Approved WhatsApp templates (HSM). Each maps to its own
            // template-bound salesbot plus the custom fields that carry the
            // template variables, in {{1}}, {{2}}... order. Sending one =
            // write these fields on the lead, then run the bot.
            'templates' => [
                'appointment_reminder' => [
                    'bot_id' => (int) env('KOMMO_REMINDER_BOT_ID', 166349),
                    'fields' => [
                        (int) env('KOMMO_REMINDER_FIELD_DATETIME', 4340765), // {{1}} fecha y hora
                        (int) env('KOMMO_REMINDER_FIELD_DOCTOR', 4340767),   // {{2}} doctor
                    ],
                ],
            ],
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
    | Queue drainer
    |--------------------------------------------------------------------------
    | Scheduled short-lived worker that keeps the WhatsApp queue moving without a
    | dedicated supervised process. Turn OFF once a real worker (a supervisor
    | process running `queue:work --queue=whatsapp`) is in place — running both
    | is harmless but redundant.
    */
    'queue_drainer' => [
        'enabled' => env('WHATSAPP_QUEUE_DRAINER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Template catalogue
    |--------------------------------------------------------------------------
    | Approved templates are read from Meta and cached: the list barely changes
    | and the inbox asks for it on every conversation. Refresh on demand with
    | `php artisan whatsapp:templates --fresh`.
    */
    'templates' => [
        'cache_minutes' => (int) env('WHATSAPP_TEMPLATES_CACHE_MINUTES', 30),
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Clinic / business
    |--------------------------------------------------------------------------
    | Used by the appointment reminders and the calendar message templates
    | (:direccion, :encuesta). Set per install via .env.
    */
    'clinic' => [
        'address'    => env('WHATSAPP_CLINIC_ADDRESS'),
        'survey_url' => env('WHATSAPP_CLINIC_SURVEY_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment reminders (scheduled)
    |--------------------------------------------------------------------------
    | The command whatsapp:appointment-reminders sends this template to every
    | patient with an appointment `days_before` days from now. Placeholders:
    | :fecha :hora :doctor :direccion. Set enabled=true to activate.
    */
    'reminders' => [
        'enabled'     => env('WHATSAPP_REMINDERS_ENABLED', false),
        'days_before' => (int) env('WHATSAPP_REMINDERS_DAYS_BEFORE', 1),

        // Which gateway sends the reminder. Kommo can't initiate a chat with a
        // patient who never wrote (no "talk"), so reminders go through the Cloud
        // API — which is built to open conversations with an approved template —
        // while the inbox keeps running on the active gateway. Null = use each
        // conversation's own gateway.
        'gateway'      => env('WHATSAPP_REMINDERS_GATEWAY', 'cloud_api'),

        // Which approved template (from the active gateway's `templates`) the
        // reminder is sent as. The gateway fills its variables in order.
        'template_key' => env('WHATSAPP_REMINDERS_TEMPLATE_KEY', 'appointment_reminder'),

        // Daily send time (clinic local time) for the scheduled run.
        'send_at'      => env('WHATSAPP_REMINDERS_SEND_AT', '08:00'),
        'timezone'     => env('WHATSAPP_REMINDERS_TIMEZONE', 'America/La_Paz'),

        // Human-readable preview stored on the message row so the inbox shows
        // readable text; the real send goes through the approved template above.
        'template'    => env('WHATSAPP_REMINDERS_TEMPLATE',
            'Buenos días! Le recordamos su cita el :fecha a horas :hora con el Dr(a). :doctor'
            .' en la clínica Odontoking :direccion.'
            ."\n\nResponda *SÍ* para confirmar o *NO* para cancelar su cita."
        ),
    ],
];
