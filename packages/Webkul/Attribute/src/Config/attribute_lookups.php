<?php

/*
|--------------------------------------------------------------------------
| Tipos de "Búsqueda" (lookup) para atributos
|--------------------------------------------------------------------------
| Cada entrada aparece en el dropdown "Tipo de Búsqueda" al crear/editar un
| atributo de tipo lookup. La clave del array es lo que se guarda en
| attributes.lookup_type; `name` es la etiqueta visible; `repository` resuelve
| las opciones (label_column para mostrar/buscar, value_column para el valor).
*/

return [

    'specialties' => [
        'name'         => 'Especialidades',
        'repository'   => \Webkul\Doctor\Repositories\SpecialtyRepository::class,
        'value_column' => 'id',
        'label_column' => 'name',
    ],

    'doctors' => [
        'name'         => 'Doctores',
        'repository'   => \Webkul\Doctor\Repositories\DoctorRepository::class,
        'value_column' => 'id',
        'label_column' => 'name',
    ],

    'products' => [
        'name'         => 'Servicios',
        'repository'   => \Webkul\Product\Repositories\ProductRepository::class,
        'value_column' => 'id',
        'label_column' => 'name',
    ],

];
