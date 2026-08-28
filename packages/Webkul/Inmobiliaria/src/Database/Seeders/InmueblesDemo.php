<?php

namespace Webkul\Inmobiliaria\Database\Seeders;

/**
 * Inmuebles reales de CENTURY 21 usados para probar el flujo del agente.
 *
 * Son anuncios publicados de verdad, no datos inventados, y esa es la razón de
 * que existan: fueron estas dos fichas las que revelaron que el esquema del
 * brief no alcanzaba —altura de galpón, modalidad de cambio, precio anterior—.
 * Un seeder ficticio habría confirmado el esquema equivocado.
 *
 * Sin fotos: la galería se carga desde la UI. `tiene_fotos` va a dar false
 * hasta que alguien suba archivos, y eso es correcto, no un bug del seeder.
 */
final class InmueblesDemo
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fichas(): array
    {
        return [
            [
                'codigo'      => 'C21-0001',
                'operacion'   => 'venta',
                'tipo'        => 'galpon',
                'estado'      => 'disponible',
                'titulo'      => 'Galpón industrial con oficinas a estrenar sobre Av. Banzer',
                'destacado'   => 'Galpón de 500 m² con piso de alto tráfico y 6,5 m de altura, más 7 oficinas, sobre Av. Banzer.',
                'descripcion' => implode("\n", [
                    'Galpón industrial con oficinas en venta, a estrenar, sobre Av. Banzer a la altura del Km 11.',
                    'Consta de: secretaría / recepción, 7 oficinas, baños para gerencia y personal, cocineta,',
                    'patio interno (iluminación / ventilación), galpón de 500 m² con piso de alto tráfico',
                    '(altura 6,5 m aprox.), espacio para instalar un showroom y parqueo interno para más de',
                    '10 vehículos.',
                ]),

                'zona'            => 'Zona Norte',
                'barrio'          => 'Av. Banzer Km 11',
                'referencia'      => 'Sobre Av. Banzer, a la altura del Km 11',
                'google_maps_url' => 'https://maps.app.goo.gl/JvUpZm73eahtyzza7',

                'precio'           => 690000,
                'moneda'           => 'USD',
                'modalidad_cambio' => 'tc_oficial',   // "en DÓLARES O AL T/C OFICIAL BCB"
                'negociable'       => true,

                'parqueos'       => 10,
                'm2_terreno'     => 1000,
                'm2_construidos' => 650,               // galpón 500 + oficinas 150

                'caracteristicas' => [
                    'altura_galpon_m'      => 6.5,
                    'm2_galpon'            => 500,
                    'm2_oficinas'          => 150,
                    'm2_parqueo_showroom'  => 350,
                    'oficinas'             => 7,
                    'piso'                 => 'Alto tráfico',
                    'parqueo_interno'      => 'Más de 10 vehículos',
                    'a_estrenar'           => true,
                    'ambientes'            => [
                        'Secretaría / recepción',
                        'Baños para gerencia y personal',
                        'Cocineta',
                        'Patio interno (iluminación / ventilación)',
                        'Espacio para showroom',
                    ],
                ],
            ],

            [
                // Código externo, no generado: viene del anuncio ya publicado.
                // Es exactamente el caso que justifica que `codigo` sea editable
                // (D9) — es el número que el cliente le va a decir al agente.
                'codigo'      => '110748',
                'operacion'   => 'venta',
                'tipo'        => 'casa',
                'estado'      => 'disponible',
                'titulo'      => 'Casa con departamento en venta a precio de terreno, Barrio Hamacas',
                'destacado'   => 'Casa con departamento independiente a precio de terreno, en Barrio Hamacas.',
                'descripcion' => implode("\n", [
                    'Casa: 2 dormitorios (1 con vestidor), baño compartido, living comedor, cocina',
                    'independiente, escritorio, baño de visita, depósito, lavandería, churrasquera techada',
                    'con galería y garaje para 4 vehículos.',
                    'Departamento independiente: 1 dormitorio, cocineta, baño, depósito y patio trasero con',
                    'árboles y plantas.',
                ]),

                'zona'            => 'Zona Norte',
                'barrio'          => 'Barrio Hamacas',
                'google_maps_url' => 'https://maps.app.goo.gl/o1AzB7gFuY3ekK959',

                'precio'           => 247000,
                'precio_anterior'  => 279000,          // el "🚨NUEVO PRECIO🚨" del anuncio
                'moneda'           => 'USD',
                'modalidad_cambio' => 'tc_paralelo',   // "en dólares o al cambio paralelo"
                'negociable'       => false,

                // 2 de la casa + 1 del departamento. El desglose queda en
                // caracteristicas: el total es lo que se filtra, el detalle es
                // lo que el agente cuenta.
                'dormitorios'    => 3,
                'banos'          => 3,
                'parqueos'       => 4,
                'm2_terreno'     => 364,
                'm2_construidos' => 167,

                'caracteristicas' => [
                    'frente_m'                   => 13,
                    'dormitorios_casa'           => 2,
                    'dormitorios_departamento'   => 1,
                    'departamento_independiente' => true,
                    'ambientes'                  => [
                        'Dormitorio principal con vestidor',
                        'Living comedor',
                        'Cocina independiente',
                        'Escritorio',
                        'Baño de visita',
                        'Depósito y lavandería',
                        'Churrasquera techada con galería',
                        'Patio trasero con árboles y plantas (departamento)',
                    ],
                ],
            ],
        ];
    }
}
