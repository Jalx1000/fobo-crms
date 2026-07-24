<?php

return [
    // Si es false, AppointmentService::process() crea las citas 100% local
    // (Lead + Activity) sin llamar a ShareMeData en absoluto: ni checkAvailability
    // ni createEvent. Util si SMD esta caido o para doctores no integrados aun.
    'validate_availability' => env('SMD_VALIDATE_AVAILABILITY', true),

    // Los ids por defecto son los del esquema sembrado (LeadPipelineSeeder):
    // 1 Consulta, 2 Cita confirmada, 3 Cirugia, 5 Cita completada,
    // 6 Cita perdida, 7 Cita cancelada. Si una instalacion tiene el pipeline
    // con otros ids, se corrige por env sin tocar codigo.
    'stage_map' => [
        'confirmed' => env('SMD_STAGE_CONFIRMED', 2),
        'completed' => env('SMD_STAGE_COMPLETED', 5),
        'cancelled' => env('SMD_STAGE_CANCELLED', 7),
        'no_show'   => env('SMD_STAGE_NO_SHOW', 6),
        'surgery'   => env('SMD_STAGE_SURGERY', 3),
        'default'   => env('SMD_STAGE_DEFAULT', 1),
    ],
    'fallback_doctor_id' => env('SMD_FALLBACK_DOCTOR_ID', null),

    // Prefijo que se antepone a los telefonos guardados en formato local para
    // armar los enlaces wa.me del detalle de cita.
    'whatsapp_country_code' => env('WHATSAPP_COUNTRY_CODE', '591'),

    // "Pacientes" que SMD usa para tapar horarios en que un medico no atiende.
    // No son personas: no deben generar contacto, ni lead, ni cita. Se comparan
    // en minusculas y por substring contra el nombre de la ficha.
    'dummy_patients' => [
        'ocupado',
        'bloqueo',
        'bloqueado',
        'no disponible',
        'reservado',
    ],

    // Deduplicacion de pacientes por smd_patient_id (identidad exacta) en vez de
    // por telefono. En false vuelve al comportamiento anterior, que buscaba por
    // telefono primero y por eso cruzaba las fichas de familiares que comparten
    // celular. Es la salida de emergencia: esto corre en el camino de toda cita
    // que entra.
    'dedupe_v2' => (bool) env('SMD_DEDUPE_V2', true),

    // Horas que se cachea la ficha de un paciente traida de la API de SMD.
    // El calendario no manda el telefono, asi que hay que pedirlo por paciente;
    // sin cache una carga masiva repetiria la misma llamada decenas de veces,
    // porque un paciente aparece en muchos eventos.
    'patient_cache_hours' => (int) env('SMD_PATIENT_CACHE_HOURS', 168),

    'dropbox' => [
        'access_token'        => env('DROPBOX_ACCESS_TOKEN'),
        'refresh_token'       => env('DROPBOX_REFRESH_TOKEN'),
        'app_key'             => env('DROPBOX_APP_KEY'),
        'app_secret'          => env('DROPBOX_APP_SECRET'),
        'appointments_folder' => env('DROPBOX_APPOINTMENTS_FOLDER', '/smd-events'),

        // Descargas simultaneas. Mas alto acelera la carga masiva pero Dropbox
        // limita la concurrencia por app y empieza a responder 429.
        'concurrency' => (int) env('SMD_DROPBOX_CONCURRENCY', 10),

        // Archivos por lote. Acota cuantos payloads viven en memoria a la vez.
        'batch_size'  => (int) env('SMD_DROPBOX_BATCH_SIZE', 100),
    ],
];
