<?php

/*
|--------------------------------------------------------------------------
| Lista de precios por variante
|--------------------------------------------------------------------------
| Los ejes reales de cada producto se cargan desde el CRM y viven en
| `products.ejes_precio`. Acá solo están los nombres que se sugieren en el
| autocompletado, para que dos personas no carguen el mismo eje como "Canal",
| "canal de venta" y "Segmento" y después los precios no se puedan comparar.
|
| Agregar uno es agregar una línea: no cambia nada de lo ya cargado.
*/

return [
    'ejes_sugeridos' => [
        'Tamaño',
        'Canal',
        'Color',
        'Presentación',
        'Impresión',
    ],

    /*
    | Tope de filas que se le devuelven al agente en la ficha de un producto.
    |
    | Existe porque la ficha entra en el prompt del modelo: una lista de 200
    | filas se lleva el contexto que el agente necesita para la conversación. Con
    | los productos de Imprimir el máximo real es 15 (5 tamaños × 3 canales), así
    | que 60 deja margen sin riesgo de recortar nada de verdad.
    */
    'max_filas_agente' => 60,
];
