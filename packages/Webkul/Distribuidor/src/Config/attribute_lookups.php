<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Distribuidores y encargados
    |--------------------------------------------------------------------------
    |
    | Los usuarios que pueden quedar como "Asesor Asignado" de un pedido o de un
    | contacto: el distribuidor que lo reparte, o un encargado que lo atiende
    | mientras no se le da a nadie (los clientes sin ciudad van a Huascar). Los
    | administradores no: nunca atienden un cliente.
    |
    | Es un lookup propio y no un filtro sobre `users` porque ese mismo lookup lo
    | usan "Propietario de Ventas" de cotizaciones y de organizaciones, donde
    | cualquier usuario corresponde.
    |
    | Los roles se nombran por su NOMBRE y no por su id: los ids de rol cambian entre
    | instalaciones, y una config que apunta al id 70 de este servidor no significa
    | nada en otro. La clave sigue siendo `distribuidores` porque es la que quedó
    | guardada en `attributes.lookup_type`.
    */
    'distribuidores' => [
        'name'         => 'Distribuidores y encargados',
        'repository'   => 'Webkul\User\Repositories\UserRepository',
        'label_column' => 'name',
        'role'         => ['Distribuidor', 'Encargado'],
    ],
];
