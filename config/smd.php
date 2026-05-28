<?php

return [
    'stage_map' => [
        'confirmed' => env('SMD_STAGE_CONFIRMED', 7),
        'completed' => env('SMD_STAGE_COMPLETED', 5),
        'cancelled' => env('SMD_STAGE_CANCELLED', 6),
        'no_show'   => env('SMD_STAGE_NO_SHOW',   6),
        'default'   => env('SMD_STAGE_DEFAULT',    1),
    ],
    'fallback_doctor_id' => env('SMD_FALLBACK_DOCTOR_ID', null),
    'dropbox' => [
        'access_token'        => env('DROPBOX_ACCESS_TOKEN'),
        'refresh_token'       => env('DROPBOX_REFRESH_TOKEN'),
        'app_key'             => env('DROPBOX_APP_KEY'),
        'app_secret'          => env('DROPBOX_APP_SECRET'),
        'appointments_folder' => env('DROPBOX_APPOINTMENTS_FOLDER', '/smd-events'),
    ],
];
