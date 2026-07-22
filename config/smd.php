<?php

return [
    // Si es false, AppointmentService::process() crea las citas 100% local
    // (Lead + Activity) sin llamar a ShareMeData en absoluto: ni checkAvailability
    // ni createEvent. Util si SMD esta caido o para doctores no integrados aun.
    'validate_availability' => env('SMD_VALIDATE_AVAILABILITY', true),

    'stage_map' => [
        'confirmed' => env('SMD_STAGE_CONFIRMED', 7),
        'completed' => env('SMD_STAGE_COMPLETED', 5),
        'cancelled' => env('SMD_STAGE_CANCELLED', 6),
        'no_show'   => env('SMD_STAGE_NO_SHOW', 8), // Cita Perdida (no Cancelado)
        'default'   => env('SMD_STAGE_DEFAULT', 1),
    ],
    'fallback_doctor_id' => env('SMD_FALLBACK_DOCTOR_ID', null),

    // Prefijo que se antepone a los telefonos guardados en formato local para
    // armar los enlaces wa.me del detalle de cita.
    'whatsapp_country_code' => env('WHATSAPP_COUNTRY_CODE', '591'),

    'dropbox' => [
        'access_token'        => env('DROPBOX_ACCESS_TOKEN'),
        'refresh_token'       => env('DROPBOX_REFRESH_TOKEN'),
        'app_key'             => env('DROPBOX_APP_KEY'),
        'app_secret'          => env('DROPBOX_APP_SECRET'),
        'appointments_folder' => env('DROPBOX_APPOINTMENTS_FOLDER', '/smd-events'),
    ],
];
