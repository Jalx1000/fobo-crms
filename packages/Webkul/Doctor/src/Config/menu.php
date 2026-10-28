<?php

return [
    [
        'key'        => 'doctors',
        'name'       => 'Doctor',
        'route'      => 'admin.doctor.index',
        'sort'       => 5,
        'icon-class' => 'icon-user',
    ],
    [
        'key'        => 'doctors.doctors',
        'name'       => 'Doctores',
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
        'name'       => 'Horarios',
        'route'      => 'admin.schedules.index',
        'sort'       => 3,
        'icon-class' => '',
    ],
];
