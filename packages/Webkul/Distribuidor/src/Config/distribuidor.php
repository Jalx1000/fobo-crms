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

        /*
        | Dónde abre el mapa cuando el cliente todavía no tiene domicilio.
        |
        | 🔴 NO se deriva del punto medio de la caja de arriba, que es lo que se hacía y
        | estaba mal: esa caja tiene margen de sobra para validar coordenadas, así que su
        | centro geométrico —lat -23.25, lng -64.75— cae en campo vacío, a 118 km de San
        | Salvador de Jujuy. El distribuidor abría "Marcar domicilio" y tenía que arrastrar
        | el mapa un centenar de kilómetros.
        |
        | Validar una zona y abrir un mapa son dos problemas distintos: el primero quiere un
        | área generosa, el segundo un punto preciso donde la gente vive.
        |
        | Zoom 14 y no 11: a 11 un píxel son 70 metros y no se distingue una cuadra de otra.
        */
        'centro_mapa' => [
            'lat'  => (float) env('DISTRIBUIDOR_MAPA_LAT', -24.1858),
            'lng'  => (float) env('DISTRIBUIDOR_MAPA_LNG', -65.2995),
            'zoom' => (int) env('DISTRIBUIDOR_MAPA_ZOOM', 14),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geocodificación inversa (del pin a la dirección escrita)
    |--------------------------------------------------------------------------
    |
    | Nominatim de OpenStreetMap: sin clave ni facturación. Su política pide identificar
    | la aplicación con un User-Agent propio —sin eso devuelve 403— y limita a una
    | consulta por segundo, que sobra para una consulta por contacto nuevo.
    |
    | Se puede apagar: la dirección pasa a escribirse a mano, que es lo que se hacía
    | antes. El pin se guarda igual en los dos casos.
    |
    */
    'geocodificacion' => [
        'enabled'    => (bool) env('DISTRIBUIDOR_GEOCODIFICAR', true),
        'timeout'    => (int) env('DISTRIBUIDOR_GEOCODIFICAR_TIMEOUT', 5),
        'user_agent' => env('DISTRIBUIDOR_GEOCODIFICAR_UA', 'SensiaCRM/1.0 (sensia.sofopolis.com)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recordatorio de recompra
    |--------------------------------------------------------------------------
    |
    | Después de la entrega, preguntarle al cliente si quiere agendar el próximo
    | pedido. Apagado por defecto, y no por cautela de más: el recordatorio sale
    | días después de la última conversación, o sea FUERA de la ventana de 24 h de
    | WhatsApp, así que EXIGE una plantilla aprobada por Meta. Mientras no exista,
    | cada corrida generaría rechazos en el log sin que nadie se entere.
    |
    | 🔴 Además necesita un scheduler corriendo (`php artisan schedule:run` cada
    | minuto). Sin eso no hay nada que despierte al comando y el sistema entero es
    | código muerto, aunque esté encendido.
    |
    */
    'recordatorios' => [
        'enabled' => (bool) env('DISTRIBUIDOR_RECORDATORIOS', false),

        /*
        | La plantilla con la que se pregunta. Una variable: el nombre del cliente.
        */
        'template' => env('DISTRIBUIDOR_RECORDATORIO_TEMPLATE', 'recordatorio_pedido'),
        'language' => env('DISTRIBUIDOR_RECORDATORIO_LANG', 'es'),

        /*
        | El archivo del encabezado, si la plantilla lo pide (`recordatorio_pedido_video`
        | lleva el video de Sensia). Si la plantilla no declara encabezado se ignora, y si
        | lo declara, el archivo tiene que ser del mismo tipo: video para VIDEO.
        */
        'media' => env('DISTRIBUIDOR_RECORDATORIO_MEDIA', env('APP_URL').'/whatsapp/bienvenida-sensia.mp4'),

        /*
        | Cada cuántos días compra un cliente del que todavía no sabemos nada.
        |
        | Se usa sólo hasta que tenga DOS entregas; a partir de ahí manda su propio
        | promedio. Ver Recordatorios::cadenciaDe().
        */
        'cadencia_defecto' => (int) env('DISTRIBUIDOR_RECORDATORIO_CADENCIA', 7),

        /*
        | Días FIJOS entre la entrega y el recordatorio, para todos los clientes.
        |
        | Sensia lo pidió así: 4 días después de la última entrega. Con un valor > 0
        | manda sobre el promedio de cada cliente (cadencia_defecto, mínima y máxima
        | dejan de usarse). En 0 vuelve el cálculo por promedio.
        */
        'cadencia_fija' => (int) env('DISTRIBUIDOR_RECORDATORIO_CADENCIA_FIJA', 4),

        /*
        | Los topes del promedio.
        |
        | Mínimo: un cliente con dos entregas seguidas en días distintos de la misma
        | semana da un promedio de 1 o 2, y recordarle cada 2 días es spam.
        | Máximo: dos compras con un año de diferencia dan un promedio que no
        | describe ningún hábito.
        */
        'cadencia_minima' => (int) env('DISTRIBUIDOR_RECORDATORIO_CADENCIA_MIN', 3),
        'cadencia_maxima' => (int) env('DISTRIBUIDOR_RECORDATORIO_CADENCIA_MAX', 60),

        /*
        | Si dice que no —o si no contesta—, cuántos días hasta el próximo intento.
        */
        'reintento_dias' => (int) env('DISTRIBUIDOR_RECORDATORIO_REINTENTO', 2),

        /*
        | Cuántos recordatorios como máximo por ciclo.
        |
        | 🔴 El tope NO es una preferencia de diseño: insistirle cada 2 días a alguien
        | que ya dijo que no termina en reportes de spam, y los reportes bajan la
        | calidad del número en Meta hasta que restringen o bloquean la línea. Es la
        | línea por la que entra TODO el negocio.
        |
        | Agotado el tope se corta, y vuelve a arrancar solo cuando el cliente compra.
        */
        'intentos_maximos' => (int) env('DISTRIBUIDOR_RECORDATORIO_INTENTOS', 3),

        /*
        | Cuántos mandar por corrida del comando. Es un freno, no una cuota: con el
        | scheduler cada hora, 100 por corrida son 2.400 por día.
        */
        'por_corrida' => (int) env('DISTRIBUIDOR_RECORDATORIO_POR_CORRIDA', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Responsable de lo que no tiene distribuidor
    |--------------------------------------------------------------------------
    |
    | Los pedidos que no son de ningún distribuidor —los que crea el agente de IA para
    | un cliente sin distribuidor, los que no tienen ciudad, los de un distribuidor dado
    | de baja— quedan a nombre de esta persona, que los reparte a mano. Hoy: Huascar.
    |
    | Por email y no por id: el id cambia entre bases (producción, pruebas), el email no.
    |
    */
    'responsable_email' => env('DISTRIBUIDOR_RESPONSABLE_EMAIL', 'huascar.arze@gmail.com'),

    /*
    |--------------------------------------------------------------------------
    | Pregunta de ciudad por WhatsApp
    |--------------------------------------------------------------------------
    |
    | Sin ciudad no hay distribuidor al que asignarle el cliente. Si el agente no la
    | consiguió en la charla, el CRM se la pregunta con una lista de ciudades para
    | tocar. Sólo se le ofrecen las ciudades que tienen distribuidor.
    |
    | 🔴 Tiene que salir dentro de las 24 h desde el último mensaje del cliente:
    | después WhatsApp sólo deja escribirle con una plantilla aprobada. Por eso
    | `ventana_horas` es 23 y no 24 — el margen cubre que el comando corre cada 10
    | minutos.
    |
    */
    'pregunta_ciudad' => [
        'enabled' => (bool) env('DISTRIBUIDOR_PREGUNTAR_CIUDAD', true),

        // Cuánto se espera desde el último mensaje del cliente antes de preguntar:
        // se le da tiempo al agente a preguntarla él, sin que el cliente reciba la
        // misma pregunta dos veces.
        'tras_minutos' => (int) env('DISTRIBUIDOR_PREGUNTAR_CIUDAD_TRAS', 60),

        'ventana_horas' => (int) env('DISTRIBUIDOR_PREGUNTAR_CIUDAD_VENTANA', 23),

        'texto' => env('DISTRIBUIDOR_PREGUNTAR_CIUDAD_TEXTO', '¿De qué ciudad nos escribís? 📍 Así te asignamos al distribuidor de tu zona.'),

        // Máximo 20 caracteres: es el botón que abre la lista en WhatsApp.
        'boton' => env('DISTRIBUIDOR_PREGUNTAR_CIUDAD_BOTON', 'Elegir ciudad'),
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
