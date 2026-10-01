<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sucursal "comodín"
    |--------------------------------------------------------------------------
    | El atributo `ciudad_producto_sucursal` de un producto es un multiselect
    | guardado como string de ids de sucursal ("1,9"). El id de abajo significa
    | "se vende en todas", y por eso entra en el catálogo de cualquier sucursal.
    |
    | Importa porque hay productos con el MISMO nombre y distinto precio según la
    | sucursal (el botellón de 20 L vale 8.000 en San Salvador de Jujuy y 7.500
    | en Barrio Alto Comedero y Palpalá). Un catálogo sin filtrar hace que el
    | distribuidor cobre el precio de otra ciudad.
    */
    'sucursal_todas' => (int) env('DISTRIBUIDOR_SUCURSAL_TODAS', 10),

    /*
    |--------------------------------------------------------------------------
    | Etapas del pedido
    |--------------------------------------------------------------------------
    | Los códigos reales de las etapas, que es lo que el Punto avanza con sus
    | botones grandes. Se nombran por código y no por id porque cada ciudad
    | (pipeline) tiene su propio juego de etapas con los mismos códigos.
    */
    'etapas' => [
        'inicial'    => 'prospectos',
        'confirmado' => 'pedidos-confirmado',
        'entregado'  => 'pedido-entregado',
        'cancelado'  => 'pedidos-cancelado',
    ],

    /*
    |--------------------------------------------------------------------------
    | Formas de cobro
    |--------------------------------------------------------------------------
    | Cerradas a propósito: un campo libre acá vuelve imposible cuadrar la caja.
    */
    'formas_de_pago' => [
        'efectivo'      => 'Efectivo',
        'transferencia' => 'Transferencia',
        'qr'            => 'QR',
    ],

    /*
    |--------------------------------------------------------------------------
    | Canal y tipo por defecto de un pedido creado en el Punto
    |--------------------------------------------------------------------------
    | `lead_source_id` y `lead_type_id` son obligatorios en este CRM. El Punto no
    | los pregunta —es una venta en mano, no un formulario— así que se fijan acá.
    */
    'canal_por_defecto' => (int) env('DISTRIBUIDOR_CANAL', 5),  // Directo

    /*
    | El tipo de cliente NO se fija: se deduce de si la persona ya tiene pedidos
    | (ver PuntoPedido::tipoDeCliente). Acá sólo viven los dos ids.
    */
    'tipo_nuevo'   => (int) env('DISTRIBUIDOR_TIPO_NUEVO', 1),    // Nuevo Cliente
    'tipo_antiguo' => (int) env('DISTRIBUIDOR_TIPO_ANTIGUO', 2),  // Cliente Antiguo
];
