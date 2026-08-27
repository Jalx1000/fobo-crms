<?php

return [
    [
        'key'   => 'inmuebles',
        'name'  => 'Inmuebles',
        'route' => 'admin.inmuebles.index',
        'sort'  => 4,
    ], [
        'key'   => 'inmuebles.create',
        'name'  => 'Crear',
        'route' => ['admin.inmuebles.create', 'admin.inmuebles.store'],
        'sort'  => 1,
    ], [
        'key'   => 'inmuebles.edit',
        'name'  => 'Editar',
        'route' => ['admin.inmuebles.edit', 'admin.inmuebles.update'],
        'sort'  => 2,
    ], [
        'key'   => 'inmuebles.delete',
        'name'  => 'Eliminar',
        'route' => 'admin.inmuebles.destroy',
        'sort'  => 3,
    ],
];
