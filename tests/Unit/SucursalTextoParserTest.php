<?php

use Webkul\Warehouse\Services\SucursalTextoParser;

/*
 * Los textos son copia literal de `warehouses.contact_address.address` en
 * producción al 16/10/2026: el equipo carga dirección, horarios y mapa en un
 * solo campo libre y en distinto orden por sucursal. Si un texto real cambia
 * de forma y el parser lo clasifica mal, agregarlo acá como un caso más.
 */

dataset('sucursales reales', [
    'Santa Cruz' => [
        "Horarios de atención:\r\nLunes a viernes\r\n7 a. m.–3 p. m.\r\nSábados\r\n7–11 a. m.\r\n\r\nQuinto anillo, Av. Pirai, Nº 5700, Santa Cruz de la Sierra\r\nhttps://maps.app.goo.gl/KT2FPmQAn1p88vBMA",
        [
            'direccion' => 'Quinto anillo, Av. Pirai, Nº 5700, Santa Cruz de la Sierra',
            'horarios'  => 'Lunes a viernes 7 a. m.–3 p. m. · Sábados 7–11 a. m.',
            'mapa_url'  => 'https://maps.app.goo.gl/KT2FPmQAn1p88vBMA',
        ],
    ],
    'Cochabamba' => [
        "Horarios de atención\r\nLunes a Vienes de 9:00hrs a 17:00hrs \r\nSábado de 9:00hrs a 13:00hrs \r\n\r\nNUESTRAS OFICINAS EN LA CIUDAD DE COCHABAMBA.\r\nC\r\nalle Mayor Rocha No 460 entre Jose Marti y Tarapaca.\r\nhttps://maps.app.goo.gl/S9ACh6uam7nXdmgn6",
        [
            // El contenido sale tal como está cargado: corregirlo es tarea del CRM.
            'direccion' => 'NUESTRAS OFICINAS EN LA CIUDAD DE COCHABAMBA. C alle Mayor Rocha No 460 entre Jose Marti y Tarapaca.',
            'horarios'  => 'Lunes a Vienes de 9:00hrs a 17:00hrs · Sábado de 9:00hrs a 13:00hrs',
            'mapa_url'  => 'https://maps.app.goo.gl/S9ACh6uam7nXdmgn6',
        ],
    ],
    'La Paz' => [
        "Horarios de atención:\r\nHorario continuo de 9:00hrs - 17:00hrs \r\nSábado de 9:00hrs - 13:00hrs \r\n\r\nCalle Boquerón Pasaje sin nombre N° 1755 B, bajando media cuadra de la Plaza El Cóndor.(Ref. Ingresando pasaje de rejas Guindas)  \r\nhttps://goo.gl/maps/BqXDAbigQFciff4p9",
        [
            'direccion' => 'Calle Boquerón Pasaje sin nombre N° 1755 B, bajando media cuadra de la Plaza El Cóndor.(Ref. Ingresando pasaje de rejas Guindas)',
            'horarios'  => 'Horario continuo de 9:00hrs - 17:00hrs · Sábado de 9:00hrs - 13:00hrs',
            'mapa_url'  => 'https://goo.gl/maps/BqXDAbigQFciff4p9',
        ],
    ],
    'Potosí' => [
        "Horarios de atención:\r\nLunes a Viernes\r\n9:00am - 11:00am / 2:30pm - 8:30pm \r\nSábado\r\n3:00pm - 8:30pm \r\n\r\nCalle Ecuador entre J. Manrique y M. Garcia  N° 85 \r\nhttps://maps.app.goo.gl/inQihLPQscC9ecBT8",
        [
            'direccion' => 'Calle Ecuador entre J. Manrique y M. Garcia N° 85',
            'horarios'  => 'Lunes a Viernes 9:00am - 11:00am / 2:30pm - 8:30pm · Sábado 3:00pm - 8:30pm',
            'mapa_url'  => 'https://maps.app.goo.gl/inQihLPQscC9ecBT8',
        ],
    ],
    'Tarija' => [
        "Horarios de atención:\r\nLunes a Viernes:\r\n8:30hrs - 12:30hrs  / 15:30hrs - 19:30hrs \r\nSábado: \r\n9:00hrs - 15:00hrs \r\n\r\nCalle 15 de Abril #275 casi esquina Méndez\r\nhttps://maps.app.goo.gl/TdwB6KnLV1Cyknbw5?g_st=iw",
        [
            // "15 de Abril" y "#275" son números de calle, no horas.
            'direccion' => 'Calle 15 de Abril #275 casi esquina Méndez',
            'horarios'  => 'Lunes a Viernes: 8:30hrs - 12:30hrs / 15:30hrs - 19:30hrs · Sábado: 9:00hrs - 15:00hrs',
            'mapa_url'  => 'https://maps.app.goo.gl/TdwB6KnLV1Cyknbw5?g_st=iw',
        ],
    ],
    'Oruro (sin horarios)' => [
        "Av: Villarroel entre Tacna y Arica\r\nhttps://goo.gl/maps/eJscE1x8rKLDe8F59",
        [
            'direccion' => 'Av: Villarroel entre Tacna y Arica',
            'horarios'  => null,
            'mapa_url'  => 'https://goo.gl/maps/eJscE1x8rKLDe8F59',
        ],
    ],
    'Sucre (dirección antes de horarios)' => [
        "Av. Manco kapac # 112\r\nLunes a viernes \r\n9:00am - 13:00pm / 15:00pm a 19:00pm \r\nhttps://maps.app.goo.gl/ihut9LV5a7MA4nXz8?g_st=awb",
        [
            'direccion' => 'Av. Manco kapac # 112',
            'horarios'  => 'Lunes a viernes 9:00am - 13:00pm / 15:00pm a 19:00pm',
            'mapa_url'  => 'https://maps.app.goo.gl/ihut9LV5a7MA4nXz8?g_st=awb',
        ],
    ],
]);

it('separa los textos reales de producción', function (string $texto, array $esperado) {
    expect((new SucursalTextoParser)->parse($texto))->toBe($esperado);
})->with('sucursales reales');

it('devuelve todo null si no hay texto', function (?string $texto) {
    expect((new SucursalTextoParser)->parse($texto))->toBe([
        'direccion' => null,
        'horarios'  => null,
        'mapa_url'  => null,
    ]);
})->with([null, '', "  \r\n \r\n"]);

it('sin calle cargada deja la dirección en null (caso Cochabamba 22/09–16/10)', function () {
    $texto = "Horarios de atención\r\nLunes a Vienes de 9:00hrs a 17:00hrs \r\nSábado de 9:00hrs a 13:00hrs \r\n\r\nhttps://maps.app.goo.gl/S9ACh6uam7nXdmgn6";

    expect((new SucursalTextoParser)->parse($texto))
        ->direccion->toBeNull()
        ->horarios->toBe('Lunes a Vienes de 9:00hrs a 17:00hrs · Sábado de 9:00hrs a 13:00hrs')
        ->mapa_url->toBe('https://maps.app.goo.gl/S9ACh6uam7nXdmgn6');
});

it('solo acepta enlaces https de Google Maps, pero quita cualquier URL del texto', function (string $url) {
    expect((new SucursalTextoParser)->parse("Av. Siempre Viva 742\r\n{$url}"))->toBe([
        'direccion' => 'Av. Siempre Viva 742',
        'horarios'  => null,
        'mapa_url'  => null,
    ]);
})->with([
    'otro dominio'     => 'https://example.com/maps/abc',
    'sin https'        => 'http://maps.app.goo.gl/abc',
    'goo.gl no mapa'   => 'https://goo.gl/abc',
    'imita el dominio' => 'https://maps.app.goo.gl.evil.com/abc',
]);

it('acepta las variantes de dominio de Google Maps', function (string $url) {
    expect((new SucursalTextoParser)->parse($url)['mapa_url'])->toBe($url);
})->with([
    'https://maps.app.goo.gl/abc',
    'https://goo.gl/maps/abc',
    'https://www.google.com/maps/place/x',
    'https://google.com/maps?q=-17.39,-66.15',
    'https://maps.google.com/?q=x',
]);

it('se queda con el primer enlace de Maps si hay varios', function () {
    expect((new SucursalTextoParser)->parse("https://example.com\r\nhttps://goo.gl/maps/uno\r\nhttps://goo.gl/maps/dos")['mapa_url'])
        ->toBe('https://goo.gl/maps/uno');
});
