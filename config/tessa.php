<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agenda de Tessa (spa estético)
    |--------------------------------------------------------------------------
    | Tessa maneja UNA sola agenda por sucursal (se atiende una paciente a la
    | vez). Las citas no se solapan y se agendan "seguidas" (pegadas al fin de
    | la cita anterior) para llenar el día sin huecos. La duración la define el
    | servicio + zona elegidos; este default es solo el fallback.
    */

    // Nombre del único recurso agendable (el operador/cabina del spa).
    'agenda_doctor_name' => env('TESSA_AGENDA_NAME', 'Agenda Tessa'),

    // Fallback de duración de slot cuando no se pasa la del servicio (minutos).
    'default_slot_minutes' => (int) env('TESSA_DEFAULT_SLOT_MINUTES', 60),

    'scheduling' => [
        // true = citas seguidas (el próximo inicio disponible = fin de la cita
        // anterior o del turno). false = grilla fija cada `default_slot_minutes`.
        'consecutive' => (bool) env('TESSA_SCHEDULING_CONSECUTIVE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features que no aplican a Tessa (se ocultan de la UI)
    |--------------------------------------------------------------------------
    | Tessa no maneja seguros ni ShareMeData (SMD). Con este flag en false se
    | ocultan de la UI los bloques de seguro y SMD (ficha de paciente, alta,
    | leads, actividades). Reversible: no borra código ni datos.
    */
    'features' => [
        'insurance_smd' => (bool) env('TESSA_INSURANCE_SMD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Horarios de atención
    |--------------------------------------------------------------------------
    | Indexado por día de la semana de Carbon (0=domingo ... 6=sábado).
    | null = cerrado. Domingos cerrado. Usado por `tessa:generate-shifts`.
    */
    'business_hours' => [
        0 => null,                 // Domingo: cerrado
        1 => ['08:00', '19:00'],   // Lunes
        2 => ['08:00', '19:00'],   // Martes
        3 => ['08:00', '19:00'],   // Miércoles
        4 => ['08:00', '19:00'],   // Jueves
        5 => ['08:00', '19:00'],   // Viernes
        6 => ['08:30', '12:30'],   // Sábado
    ],

];
