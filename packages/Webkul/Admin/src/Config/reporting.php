<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Qué cuenta como venta
    |--------------------------------------------------------------------------
    | ANTES ESTO SE ADIVINABA POR EL NOMBRE DE LA ETAPA y el resultado fue que
    | el tablero reportaba cero ventas teniendo cierres reales en la base.
    |
    | La heurística buscaba `code` igual a 'won' o 'pedido-entregado', o un
    | nombre que contuviera «won», «ganado» o «entregado». El pipeline de esta
    | instancia usa «Cierre exitoso» y «Desistimiento», que no coinciden con
    | ninguno de esos patrones: `wonStageIds` quedaba vacío y toda métrica de
    | venta daba 0 en silencio, sin error ni aviso.
    |
    | Acá se declara explícitamente, por `code`, que es estable aunque alguien
    | renombre la etapa en la interfaz. Si se agrega una etapa nueva de cierre
    | —«Escriturado», por ejemplo— se suma a esta lista y nada más.
    |
    | La heurística sigue existiendo como respaldo (`fallback_heuristica`) para
    | que una instancia sin configurar no se quede sin métricas de golpe. Pero
    | lo que vale es la lista: si está, se usa.
    */
    'stages' => [
        // Cierres. Un lead en cualquiera de estas etapas es una venta.
        'won' => array_filter(explode(',', (string) env(
            'REPORTING_WON_STAGE_CODES',
            'cierre-exitoso,won,pedido-entregado'
        ))),

        // Perdidos. Salen del embudo sin comprar.
        'lost' => array_filter(explode(',', (string) env(
            'REPORTING_LOST_STAGE_CODES',
            'desistimiento,lost,devolucin'
        ))),

        /*
        | Comprometidos pero sin cerrar. Para el gerente son el dato más
        | accionable del mes: plata que está a un paso de entrar y que todavía
        | se puede perder.
        */
        'comprometido' => array_filter(explode(',', (string) env(
            'REPORTING_COMMITTED_STAGE_CODES',
            'reserva,anticipo---garanta'
        ))),

        // Si las listas de arriba no encuentran ninguna etapa, se vuelve a
        // adivinar por nombre antes que dejar el tablero en cero.
        'fallback_heuristica' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quién no es vendedor
    |--------------------------------------------------------------------------
    | Roles y usuarios que no entran en los rankings de venta.
    |
    | OJO CON ESTO: hoy el rol «Administrador» está excluido, y en esta
    | instancia hay tres usuarios con ese rol —Administrador, Agente y una
    | persona real—. Si esa persona vende, hoy es invisible en las estadísticas.
    | Excluir por rol es cómodo y se equivoca seguido; `ignored_users` permite
    | la excepción puntual sin tocar el rol de nadie.
    */
    // Administradores y supervisores no son asesores: no entran en los
    // gráficos por asesor del tablero (pedido del 15/10/2026).
    'ignored_roles' => array_filter(explode(',', (string) env(
        'REPORTING_IGNORED_ROLES',
        'Administrador,Supervisores'
    ))),

    // La cuenta comodín a la que se asignan los leads sin dueño.
    'ignored_users' => array_filter(explode(',', (string) env(
        'REPORTING_IGNORED_USERS',
        'Agente'
    ))),

    /*
    |--------------------------------------------------------------------------
    | Quién ve el total de la empresa
    |--------------------------------------------------------------------------
    | Roles que ven el tablero completo y todas las conversaciones de WhatsApp.
    | Cualquier otro rol (los asesores) ve solo lo de sus propios leads.
    |
    | POR ROL Y NO POR `view_permission`, a propósito: el supervisor tiene que ver
    | el total, y depender del permiso de vista repartiría la regla entre dos
    | pantallas de configuración. Spec: docs/specs/tablero-por-asesor.md (D1).
    */
    'roles_con_total' => ['Administrador', 'Supervisores'],

    /*
    | Roles SIN NINGUNA restricción: ven y hacen todo en todos los módulos
    | (conversaciones sin asignar, tomar cualquier derivación, cambiar el
    | asesor de un lead). El supervisor ve el total, pero no entra acá.
    */
    'roles_sin_restriccion' => ['Administrador'],

    /*
    |--------------------------------------------------------------------------
    | Metas de venta
    |--------------------------------------------------------------------------
    | Qué se puede medir con una meta. La lista vive acá y no como enum en la
    | base para que agregar «visitas» no requiera una migración.
    |
    | `agregacion` dice cómo se calcula el avance:
    |   cuenta  -> cuántos leads llegaron a una etapa de cierre
    |   suma    -> la suma de `lead_value` de esos leads
    |
    | El avance nunca se guarda: se calcula contra los leads cada vez. Un cierre
    | se carga el lunes con fecha del viernes, y un avance guardado se queda con
    | el número viejo.
    */
    'goals' => [
        'metricas' => [
            'ventas' => [
                'label'      => 'Cierres',
                'agregacion' => 'cuenta',
                'sufijo'     => '',
            ],

            'monto' => [
                'label'      => 'Monto cerrado',
                'agregacion' => 'suma',
                'sufijo'     => '',
            ],

            'leads' => [
                'label'      => 'Leads nuevos',
                'agregacion' => 'cuenta_todos',
                'sufijo'     => '',
            ],
        ],
    ],
];
