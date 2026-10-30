<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cuenta "Sin asignar"
    |--------------------------------------------------------------------------
    |
    | Dueña de los leads creados sin un usuario en contexto (p.ej. la
    | sincronización SMD/Dropbox que corre por cron sin sesión). Se resuelve
    | por email para no depender de un id fijo entre entornos. Su rol debe ser
    | NO-admin (para que el tablero por-vendedor no la oculte) y distinto de
    | "Recepcionista" (para no inflar el card de tiempo de respuesta).
    |
    */
    'unassigned_user' => [
        'name'  => 'Sin asignar',
        'email' => env('DASHBOARD_UNASSIGNED_USER_EMAIL', 'sin-asignar@sistema.local'),
        'role'  => 'Sistema',
    ],
];
