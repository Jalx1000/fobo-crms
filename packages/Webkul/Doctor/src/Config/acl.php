<?php

return [
    [
        'key'   => 'doctors',
        'name'  => 'Doctores',
        'route' => 'admin.doctor.index',
        'sort'  => 5,
    ],
    [
        'key'   => 'doctors.doctors',
        'name'  => 'Doctores',
        'route' => 'admin.doctor.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'doctors.doctors.create',
        'name'  => 'Crear',
        'route' => ['admin.doctor.create', 'admin.doctor.store'],
        'sort'  => 1,
    ],
    [
        'key'   => 'doctors.doctors.edit',
        'name'  => 'Editar',
        'route' => ['admin.doctor.edit', 'admin.doctor.update'],
        'sort'  => 2,
    ],
    [
        'key'   => 'doctors.doctors.delete',
        'name'  => 'Eliminar',
        'route' => 'admin.doctor.delete',
        'sort'  => 3,
    ],
    [
        'key'   => 'doctors.specialties',
        'name'  => 'Especialidades',
        'route' => 'admin.specialties.index',
        'sort'  => 2,
    ],
    [
        'key'   => 'doctors.specialties.create',
        'name'  => 'Crear',
        'route' => ['admin.specialties.create', 'admin.specialties.store'],
        'sort'  => 1,
    ],
    [
        'key'   => 'doctors.specialties.edit',
        'name'  => 'Editar',
        'route' => ['admin.specialties.edit', 'admin.specialties.update'],
        'sort'  => 2,
    ],
    [
        'key'   => 'doctors.specialties.delete',
        'name'  => 'Eliminar',
        'route' => 'admin.specialties.delete',
        'sort'  => 3,
    ],
    [
        'key'   => 'doctors.schedules',
        'name'  => 'Horarios',
        'route' => 'admin.schedules.index',
        'sort'  => 3,
    ],
    [
        'key'   => 'doctors.schedules.create',
        'name'  => 'Crear',
        'route' => ['admin.schedules.create', 'admin.schedules.store'],
        'sort'  => 1,
    ],
    [
        'key'   => 'doctors.schedules.edit',
        'name'  => 'Editar',
        'route' => ['admin.schedules.edit', 'admin.schedules.update'],
        'sort'  => 2,
    ],
    [
        'key'   => 'doctors.schedules.delete',
        'name'  => 'Eliminar',
        'route' => ['admin.schedules.delete', 'admin.schedules.delete_all', 'admin.schedules.delete_range'],
        'sort'  => 3,
    ],
];
