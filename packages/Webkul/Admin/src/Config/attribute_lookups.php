<?php

return [
    'leads' => [
        'name'         => 'Leads',
        'repository'   => 'Webkul\Lead\Repositories\LeadRepository',
        'label_column' => 'title',
    ],

    'lead_sources' => [
        'name'         => 'Lead Sources',
        'repository'   => 'Webkul\Lead\Repositories\SourceRepository',
    ],

    'lead_types' => [
        'name'         => 'Lead Types',
        'repository'   => 'Webkul\Lead\Repositories\TypeRepository',
    ],

    'lead_pipelines' => [
        'name'         => 'Lead Pipelines',
        'repository'   => 'Webkul\Lead\Repositories\PipelineRepository',
    ],

    'lead_pipeline_stages' => [
        'name'         => 'Lead Pipeline Stages',
        'repository'   => 'Webkul\Lead\Repositories\StageRepository',
    ],

    'users' => [
        'name'         => 'Sales Owners',
        'repository'   => 'Webkul\User\Repositories\UserRepository',
    ],

    /*
     | Usuarios con rol de asesor. Es el lookup del propietario de los leads;
     | `users` sigue igual para el resto de las entidades (contactos, etc.).
     | Los roles se leen de `inmobiliaria.inmuebles.roles_asesores`.
     */
    'asesores' => [
        'name'         => 'Asesores',
        'repository'   => 'Webkul\User\Repositories\UserRepository',
        'roles_config' => 'inmobiliaria.inmuebles.roles_asesores',
    ],

    'organizations' => [
        'name'         => 'Organizations',
        'repository'   => 'Webkul\Contact\Repositories\OrganizationRepository',
    ],

    'persons' => [
        'name'         => 'Persons',
        'repository'   => 'Webkul\Contact\Repositories\PersonRepository',
    ],

    'warehouses' => [
        'name'         => 'Warehouses',
        'repository'   => 'Webkul\Warehouse\Repositories\WarehouseRepository',
    ],

    'locations' => [
        'name'         => 'Locations',
        'repository'   => 'Webkul\Warehouse\Repositories\LocationRepository',
    ],

    'products' => [
        'name'         => 'Products',
        'repository'   => 'Webkul\Product\Repositories\ProductRepository',
    ],
];
