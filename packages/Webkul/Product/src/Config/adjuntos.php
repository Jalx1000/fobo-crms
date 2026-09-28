<?php

/*
|--------------------------------------------------------------------------
| Envío de adjuntos de producto al cliente
|--------------------------------------------------------------------------
| Cargar y enviar son dos límites distintos: el asesor puede subir 20 archivos
| por tanda, pero mandarle 20 imágenes seguidas a un cliente por WhatsApp le
| inunda el chat y hace que deje de mirarlas.
*/

return [
    'envio' => [
        // Cuántos manda el agente si no pide una cantidad explícita.
        'cantidad_default' => 3,

        // Techo duro. Ni el agente ni el asesor pueden superarlo.
        'cantidad_max' => 10,
    ],
];
