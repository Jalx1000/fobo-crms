<?php

/*
|--------------------------------------------------------------------------
| Características propias de cada categoría de producto
|--------------------------------------------------------------------------
| Lo que no entra en los campos fijos (SKU, nombre, precio, cantidad) y varía
| según qué se vende: el gramaje importa en un embalaje y no en un banner.
|
| Se declaran acá para que el formulario ofrezca los campos correctos en vez de
| pedirle al asesor que invente el nombre de la clave. Sin esto, dos personas
| cargando el mismo dato escriben "gramaje", "gramaje_papel" y "grs", y después
| no hay forma de compararlos.
|
| Agregar un campo es agregar una línea acá: no hace falta migración, porque
| todo vive en la columna JSON `caracteristicas` de `products`.
|
| Tipos admitidos:
|   (sin 'tipo')      → texto libre
|   'tipo' => boolean → Sí / No
|   'tipo' => lista   → varios valores separados por coma
|
| La clave del mapa es el slug de la opción de la categoría (atributo
| `product_type`). "División de Embalajes" → 'division-de-embalajes'.
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Comunes a todas las categorías
    |--------------------------------------------------------------------------
    | Se ofrecen siempre, incluso en categorías sin mapa propio.
    */
    'comunes' => [
        'tiempo_produccion' => ['label' => 'Tiempo de producción'],
        'minimo_pedido'     => ['label' => 'Pedido mínimo'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Por categoría
    |--------------------------------------------------------------------------
    | ⚠️ PROVISIONAL — los campos de 'division-de-embalajes' son una propuesta,
    | no los datos reales de imprimir. Está pendiente que el negocio confirme
    | qué se carga de verdad (Q1 y Q2 del spec). Cambiarlos es editar estas
    | líneas: no hay migración ni datos que migrar mientras nadie los haya
    | cargado todavía.
    */
    'por_categoria' => [
        'division-de-embalajes' => [
            'material'   => ['label' => 'Material'],
            'gramaje'    => ['label' => 'Gramaje', 'sufijo' => 'g/m²'],
            'medidas'    => ['label' => 'Medidas (largo × ancho × alto)'],
            'troquelado' => ['label' => '¿Troquelado?', 'tipo' => 'boolean'],
            'acabados'   => ['label' => 'Acabados', 'tipo' => 'lista'],
        ],
    ],
];
