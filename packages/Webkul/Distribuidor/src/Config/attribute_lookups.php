<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Distribuidores
    |--------------------------------------------------------------------------
    |
    | Los usuarios que pueden quedar como "Asesor Asignado" de un pedido o de un
    | contacto: el asesor es quien reparte, así que sólo pueden ser distribuidores.
    |
    | Es un lookup propio y no un filtro sobre `users` porque ese mismo lookup lo
    | usan "Propietario de Ventas" de cotizaciones y de organizaciones, donde un
    | encargado sí corresponde.
    |
    | El rol se nombra por su NOMBRE y no por su id: los ids de rol cambian entre
    | instalaciones, y una config que apunta al id 70 de este servidor no significa
    | nada en otro.
    */
    'distribuidores' => [
        'name'         => 'Distribuidores',
        'repository'   => 'Webkul\User\Repositories\UserRepository',
        'label_column' => 'name',
        'role'         => 'Distribuidor',
    ],
];
