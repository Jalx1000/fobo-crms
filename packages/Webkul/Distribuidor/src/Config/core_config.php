<?php

return [
    [
        'key'  => 'distribuidor',
        'name' => 'Distribuidor',
        'info' => 'El Punto de venta del distribuidor y sus automatismos.',
        'icon' => 'icon-dashboard',
        'sort' => 6,
    ], [
        'key'    => 'distribuidor.recordatorios',
        'name'   => 'Recordatorio de recompra',
        'info'   => 'Preguntarle al cliente por WhatsApp si quiere repetir el pedido, '
            .'cuando ya debería estar por quedarse sin agua. El momento se calcula con el '
            .'ritmo de compra de cada uno.',
        'sort'   => 1,
        'fields' => [
            [
                'name'  => 'enabled',
                'title' => 'Activar los recordatorios',
                'type'  => 'boolean',
                /*
                 * 🔴 `default` en true y NO en false, aunque la funcionalidad arranque
                 * apagada.
                 *
                 * `core()->getConfigData()` devuelve el default declarado cuando nadie
                 * guardó la configuración todavía — no devuelve null—. Con default en
                 * false, este interruptor contestaba "apagado" desde el día cero y
                 * pisaba al .env, así que encender DISTRIBUIDOR_RECORDATORIOS no hacía
                 * absolutamente nada y no había forma de darse cuenta.
                 *
                 * Quien decide si la funcionalidad existe es el .env —mientras la
                 * plantilla no esté aprobada en Meta no puede estar encendida en ningún
                 * lado—; éste es el interruptor del negocio para apagarla después.
                 */
                'default' => true,
            ],
        ],
    ],
];
