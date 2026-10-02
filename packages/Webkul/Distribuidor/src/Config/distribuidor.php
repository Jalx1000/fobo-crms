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
    | Zona de reparto
    |--------------------------------------------------------------------------
    | Se reparte sólo en Jujuy, Argentina: San Salvador de Jujuy, Palpalá y
    | Barrio Alto Comedero (que es un barrio de la capital).
    |
    | El recuadro NO es decoración: las coordenadas llegan a veces mal formadas
    | —n8n guardó "latitude17754821longitude631973421", sin punto ni signo— y hay
    | que reconstruirlas. Validar contra una zona chica es lo que distingue una
    | reconstrucción correcta de una que da un punto cualquiera. Un pin equivocado
    | manda al distribuidor a otra dirección, que es peor que no tener pin.
    |
    | Cubre la provincia entera con margen, y excluye de hecho a Bolivia (Santa
    | Cruz está en -17.7) y al resto del país.
    */
    'zona' => [
        'lat_min' => (float) env('DISTRIBUIDOR_LAT_MIN', -25.0),
        'lat_max' => (float) env('DISTRIBUIDOR_LAT_MAX', -21.5),
        'lng_min' => (float) env('DISTRIBUIDOR_LNG_MIN', -67.5),
        'lng_max' => (float) env('DISTRIBUIDOR_LNG_MAX', -62.0),

        /*
        | Lo que se le agrega a una dirección escrita antes de buscarla en el
        | mapa. Sin esto, "calle 6 nro 234" matchea en cualquier ciudad del país
        | y el mapa abre en el lugar equivocado.
        */
        'sufijo_busqueda' => env('DISTRIBUIDOR_SUFIJO_BUSQUEDA', 'Jujuy, Argentina'),
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
