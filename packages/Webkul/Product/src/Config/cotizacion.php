<?php

/*
|--------------------------------------------------------------------------
| Cotización — formato RG-VCO-005
|--------------------------------------------------------------------------
| Lo que la propuesta comercial de Imprimir lleva fijo y que NO es de una
| cotización en particular: el código del documento del sistema de calidad,
| la validez por defecto y el texto legal.
|
| Va en config y no en la plantilla porque cambia sin que cambie el código:
| el sistema de calidad reemite el documento con otra versión, la validez se
| negocia por temporada, y el texto legal lo redacta legales.
*/

return [
    /*
    | Identificación del documento en el sistema de calidad de Imprimir. Sale
    | en la cabecera de cada PDF. Se toma tal cual de la propuesta real.
    */
    'documento' => [
        'codigo'  => 'RG-VCO-005',
        'version' => '00',
        'edicion' => '19/4/2024',
        'titulo'  => 'PROPUESTA COMERCIAL MASIVOS',
    ],

    /*
    | Días de validez por defecto. Es un valor por defecto, no una imposición:
    | el asesor cambia la fecha en el formulario. Lo usan la pantalla de alta y
    | la cotización que pre-elabora el agente, para que las dos venzan igual.
    */
    'validez_dias' => 10,

    'texto_legal' => 'PARA REALIZAR SU PEDIDO, DEBERÁ EMITIR UNA ORDEN DE COMPRA O DEVOLVER FIRMADA ESTA COTIZACIÓN A IMPRIMIR. '
        .'EN ESPERA DE CONTAR CON SU CONFIANZA Y PODER BRINDARLE NUESTROS PRODUCTOS NOS DESPEDIMOS CON LAS CONSIDERACIONES MÁS DISTINGUIDAS.',

    /*
    | Forma de pago con la que arranca una cotización nueva y la que usa el
    | agente. Tiene que coincidir con el NOMBRE de una opción del atributo
    | `forma_pago`; se resuelve por nombre y no por id por lo mismo que la
    | marca: si alguien recrea la opción, el id cambia.
    */
    'forma_pago_default' => 'Al contado',

    /*
    | Las ciudades donde Imprimir atiende. Definen las columnas de la matriz de
    | responsables por producto y las opciones de sucursal de la cotización.
    |
    | Acá y no en la base porque son la misma lista en los dos lados: tenerla
    | duplicada haría que agregar una ciudad funcione en un lugar y no en otro.
    */
    'ciudades' => ['Santa Cruz', 'La Paz', 'Cochabamba'],

    /*
    | La «ciudad» que significa todo el país en la matriz de responsables. Un
    | asesor con cobertura nacional atiende cualquier ciudad, pero solo cuando
    | en esa ciudad no hay nadie para ese producto: el local va primero.
    */
    'cobertura_nacional' => 'Bolivia',

    /*
    | El asesor de quien no dice ciudad, o de una ciudad que no atiende nadie,
    | por EMAIL. Tiene que figurar en la matriz «Atiende»: si no, se usa el
    | turno de la cobertura nacional. Nunca un administrador: no vende.
    */
    'asesor_por_defecto' => env('ASESOR_POR_DEFECTO'),

    /*
    | Ejecutivos que NO entran en el turno de los clientes sin ciudad (emails,
    | separados por coma). Siguen atendiendo lo que la matriz les asigna.
    */
    'turno_sin_ciudad' => (bool) env('TURNO_SIN_CIUDAD', true),

    'fuera_del_turno' => array_filter(explode(',', (string) env('ASESORES_FUERA_DEL_TURNO', ''))),

    /*
    | Las ciudades que puede tener un CLIENTE, que son más que aquellas donde
    | Imprimir tiene gente: se vende a todo el país. Son las opciones del
    | atributo `ciudad` de contactos, oportunidades y cotizaciones.
    |
    | Una ciudad que no está en `ciudades` la atiende igual quien tiene
    | cobertura nacional, así que la lista larga no deja a nadie sin asesor:
    | solo evita perder el dato bajo un «Otra».
    */
    'ciudades_cliente' => [
        'Santa Cruz',
        'La Paz',
        'Cochabamba',
        'El Alto',
        'Sucre',
        'Oruro',
        'Potosí',
        'Tarija',
        'Beni',
        'Pando',
        'Exterior',
    ],

    /*
    | Ciudades del cliente que NO van en la cotización (pedido del 13/10): el
    | contacto y el pedido las tienen, la cotización no.
    */
    'ciudades_sin_cotizacion' => ['El Alto', 'Exterior'],

    /*
    | Pedido que nace sin ciudad: a los N minutos, si nadie lo atendió y sigue
    | sin ciudad, pasa al asesor nacional de su producto (sin derivar ni
    | apagar la IA). 0 = apagado. Spec docs/specs/asignar-sin-ciudad.md.
    */
    'sin_ciudad' => [
        'asignar_minutos' => (int) env('SIN_CIUDAD_ASIGNAR_MINUTOS', 60),
    ],

    /*
    | Horario en el que el equipo atiende. Fuera de él las consultas se asignan
    | igual —el asesor las ve al día siguiente— y el agente le avisa al cliente.
    */
    'horario_atencion' => ['desde' => '08:00', 'hasta' => '19:00'],

    /*
    | Días laborales (ISO: 1 = lunes … 7 = domingo). Con horario_atencion son
    | las horas hábiles de «Tiempo en responder» (spec tiempo-en-responder).
    */
    'dias_laborales' => [1, 2, 3, 4, 5],

    /*
    | Lo que el agente dice cuando el cliente no está en la ciudad de las
    | plantas: no hace falta que venga, se le envía.
    */
    /*
    | Seguimiento de la cotización enviada que el cliente no contesta: a los
    | `recordatorio_dias` un correo al asesor; a los `sin_interes_dias` el
    | pedido pasa a SIN INTERÉS. 0 desactiva cada paso.
    */
    'seguimiento' => [
        'enabled'           => env('COTIZACION_SEGUIMIENTO', true),
        'recordatorio_dias' => (int) env('COTIZACION_RECORDATORIO_DIAS', 3),
        'sin_interes_dias'  => (int) env('COTIZACION_SIN_INTERES_DIAS', 7),
    ],

    'envios_fuera_de_ciudad' => 'Las plantas están en Santa Cruz. Si el cliente está en otra ciudad, se le hacen envíos.',
];
