<?php

return [
    [
        'key'        => 'doctors',
        'name'       => 'Interno',
        'route'      => 'admin.doctor.index',
        'sort'       => 5,
        'icon-class' => 'icon-user',
    ],
    [
        'key'        => 'doctors.doctors',
        'name'       => 'Sucursales',
        'route'      => 'admin.doctor.index',
        'sort'       => 1,
        'icon-class' => '',
    ],
    [
        'key'        => 'doctors.specialties',
        'name'       => 'Línea',
        'route'      => 'admin.specialties.index',
        'sort'       => 2,
        'icon-class' => '',
    ],
    [
        'key'        => 'doctors.schedules',
        'name'       => 'Horarios de atención',
        'route'      => 'admin.schedules.index',
        'sort'       => 3,
        'icon-class' => '',
    ],
];
