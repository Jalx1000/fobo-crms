<?php

/*
|--------------------------------------------------------------------------
| Alcance de los datos (spec docs/specs/tablero-por-asesor.md)
|--------------------------------------------------------------------------
|
| Webkul\Admin\Helpers\Alcance lee estas listas de nombres de rol
| (`roles.name`, exactos). Es el único lugar donde se decide quién ve qué.
|
*/

return [
    // Ven el total de la empresa en el tablero y todas las conversaciones (D1).
    'roles_con_total' => ['Administrador', 'Supervisores'],

    // Sin ninguna restricción en ningún módulo (D11).
    'roles_sin_restriccion' => ['Administrador'],
];
