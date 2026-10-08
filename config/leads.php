<?php

/*
|--------------------------------------------------------------------------
| Avisos de pedidos
|--------------------------------------------------------------------------
*/

return [
    /*
    | Copias fijas del aviso de "pedido confirmado", además del asesor dueño
    | del pedido. Separadas por coma en el .env:
    |
    |     LEADS_CONFIRMED_ORDER_COPIES=jalejandromogro1@gmail.com,otro@ejemplo.com
    */
    'confirmed_order_copies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LEADS_CONFIRMED_ORDER_COPIES', ''))
    ))),
];
