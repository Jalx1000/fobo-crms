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
        'codigo' => 'RG-VCO-005',
        'version' => '00',
        'edicion' => '19/4/2024',
        'titulo' => 'PROPUESTA COMERCIAL MASIVOS',
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
        'Trinidad',
        'Cobija',
        'Exterior',
    ],

    /*
    | Ciudades del cliente que NO van en la cotización (pedido del 13/10): el
    | contacto y el pedido las tienen, la cotización no.
    */
    'ciudades_sin_cotizacion' => ['El Alto', 'Exterior'],

    /*
    | Horario en el que el equipo atiende. Fuera de él las consultas se asignan
    | igual —el asesor las ve al día siguiente— y el agente le avisa al cliente.
    */
    'horario_atencion' => ['desde' => '08:00', 'hasta' => '19:00'],
];
