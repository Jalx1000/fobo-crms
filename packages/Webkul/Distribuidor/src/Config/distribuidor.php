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

        /*
        | Con qué etapa NACE un pedido tomado en el Punto.
        |
        | Confirmado, no prospecto: en el Punto lo carga una persona con el cliente
        | delante, así que ya está confirmado por definición. Arrancarlo como
        | prospecto obligaba a cargarlo, cambiar de pestaña, confirmarlo y volver —
        | dos cambios de pestaña para una sola interacción con el cliente, medido
        | recorriendo el flujo a mano.
        |
        | `inicial` queda para los pedidos que entran por otro lado (el agente de
        | WhatsApp, la web), donde todavía nadie los confirmó. Si el Punto alguna vez
        | necesita ese paso, se cambia esta línea y vuelve.
        */
        'al_crear'   => env('DISTRIBUIDOR_ETAPA_AL_CREAR', 'pedidos-confirmado'),
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

        /*
        | Hasta cuántos metros de error se acepta una ubicación tomada con el GPS.
        |
        | La caja de arriba NO se le aplica al GPS, y es a propósito. Esa caja existe
        | para validar la RECONSTRUCCIÓN de los pines de WhatsApp, que llegan
        | mutilados y hay que adivinar dónde va el signo y la coma: ahí una zona
        | chica es lo único que distingue una reconstrucción correcta de un punto
        | cualquiera. El GPS del navegador no se reconstruye, viene bien formado.
        |
        | Aplicarle la caja al GPS no protegía de nada —cualquier lectura de un
        | distribuidor parado en Jujuy cae dentro de la caja igual— y en cambio
        | rechazaba coordenadas válidas tomadas desde otra provincia o país, que es
        | lo que pasa al probar la aplicación fuera de la zona de reparto.
        |
        | Lo que de verdad distingue "esto es la puerta del cliente" de "esto es la
        | antena de telefonía" es la PRECISIÓN que informa el navegador, no la
        | geografía. Un GPS enganchado da 5-20 m; una ubicación por wifi o por antena
        | da cientos o miles.
        */
        'precision_maxima' => (int) env('DISTRIBUIDOR_PRECISION_MAXIMA', 500),
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
