<?php

/*
|--------------------------------------------------------------------------
| Reglas de negocio del catálogo de inmuebles
|--------------------------------------------------------------------------
| Datos puros, sin lógica — igual que catalogo.php. Lo que se decide acá define
| qué ve Sofía cuando llama buscar_inmuebles(), así que un cambio en este
| archivo cambia lo que el agente le ofrece al cliente.
|
| Spec: docs/specs/agente-ia-inmuebles-c21.md (decisión D4)
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Operaciones y tipos
    |--------------------------------------------------------------------------
    | `operaciones` es un enum en la base: la lista es estable y gobierna cómo
    | busca el agente, y coincide con el primer menú del flujo (comprar /
    | alquilar / anticrético).
    |
    | `tipos` es un string validado contra esta lista, porque va a crecer
    | (penthouse, depósito) sin necesitar una migración. Las claves son las
    | mismas palabras que el agente muestra en su menú: si el menú dice "Lote",
    | la API tiene que aceptar 'lote', o la búsqueda falla con 422 justo después
    | de que el cliente eligió. `oficina` y `local` no están en el menú pero se
    | venden igual, y entran por la opción "Otra propiedad".
    */
    'operaciones' => ['venta', 'alquiler', 'anticretico'],

    'tipos' => [
        'casa'         => 'Casa',
        'quinta'       => 'Quinta',
        'departamento' => 'Departamento',
        'galpon'       => 'Galpón',
        'lote'         => 'Lote / Terreno',
        'oficina'      => 'Oficina',
        'local'        => 'Local comercial',
        'otro'         => 'Otra propiedad',
    ],

    /*
    |--------------------------------------------------------------------------
    | Estados posibles de un inmueble
    |--------------------------------------------------------------------------
    | El ciclo de vida completo. Es la lista maestra del enum de la migración.
    */
    'estados' => [
        'borrador',    // cargado a medias, el asesor todavía lo está armando
        'disponible',  // publicado y se puede visitar
        'reservado',   // hay una reserva/anticipo, pero la operación no cerró
        'vendido',
        'alquilado',
        'suspendido',  // el propietario lo bajó temporalmente
    ],

    /*
    |--------------------------------------------------------------------------
    | Qué estados devuelve buscar_inmuebles()  ← TODO: definir
    |--------------------------------------------------------------------------
    | El handoff (§6) dice "devuelve **solo disponibles**", pero no define qué
    | cuenta como disponible. La decisión tiene consecuencias comerciales reales:
    |
    |  - Solo 'disponible'          → Sofía nunca ofrece algo que no se puede
    |                                 cerrar. Conservador. Riesgo: si el asesor
    |                                 se olvida de publicar, el inmueble no
    |                                 existe para el agente.
    |
    |  - 'disponible' + 'reservado' → captura al interesado de la reserva que se
    |                                 cae (pasa seguido). Riesgo: el cliente
    |                                 visita algo que no puede comprar y se
    |                                 quema la confianza — salvo que Sofía lo
    |                                 aclare, y Sofía no negocia ni matiza.
    |
    | DECIDIDO: solo 'disponible'. Sofía nunca ofrece algo que no se puede
    | cerrar, y así no hace falta que aclare nada — que es justo lo que su
    | prompt le prohíbe hacer. Si mañana se quiere capturar al interesado de
    | una reserva caída, agregar 'reservado' acá es un cambio de una línea,
    | pero exige primero decidir cómo lo marca la ficha.
    */
    'estados_buscables' => [
        'disponible',
    ],

    /*
    |--------------------------------------------------------------------------
    | Moneda: nunca se convierte
    |--------------------------------------------------------------------------
    | El mercado boliviano es bimonetario. Cada inmueble se guarda con la moneda
    | en que el propietario lo puso (columna `moneda`, seleccionada por el asesor
    | en el formulario) y SIEMPRE se muestra en esa moneda. No hay conversión en
    | ningún punto: ni al mostrar, ni al filtrar, ni al comparar.
    |
    | Por eso no existe una clave 'tipo_cambio' acá, y no debe agregarse: el
    | oficial (6.96, clavado desde 2011) y el paralelo divergen lo suficiente
    | como para que cualquier número fijo mienta sobre el precio de una casa.
    |
    | Consecuencia en buscar_inmuebles(): el rango precio_min/precio_max se
    | aplica SOLO a las fichas cuya `moneda` coincide con la moneda pedida. Las
    | de la otra moneda no se filtran por precio — se devuelven igual, con su
    | precio literal, y el cliente compara por sí mismo.
    */
    'moneda_por_defecto' => 'USD',
    'monedas'            => ['USD', 'BOB'],

    /*
    |--------------------------------------------------------------------------
    | Límites de la búsqueda y del envío de media
    |--------------------------------------------------------------------------
    */
    'limite_busqueda_default' => 3,   // Sofía ofrece 2-3, no una lista larga
    'limite_busqueda_max'     => 10,
    'media_cantidad_default'  => 4,
    'media_cantidad_max'      => 8,   // tope duro: no inundar el chat del cliente

    /*
    |--------------------------------------------------------------------------
    | Código del inmueble
    |--------------------------------------------------------------------------
    | Es el ÚNICO id que el LLM manipula (§2 del handoff), porque es el que el
    | cliente ve en el anuncio. El CRM propone uno al crear, y el asesor lo puede
    | pisar si el anuncio ya se publicó con otro código. Único en cualquier caso.
    */
    'codigo' => [
        'prefijo'  => 'C21-',
        'relleno'  => 4,      // C21-0001
        'editable' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Entrega de media al cliente
    |--------------------------------------------------------------------------
    | 'media_id': la foto se sube a la Cloud API cuando el asesor la carga en el
    | CRM, y el envío es solo una referencia. Es lo que mejor le llega al
    | cliente: Meta no tiene que descargar nada de nuestro servidor en el momento
    | del envío, así que un CRM lento o recién reiniciado no rompe la entrega.
    |
    | 'url_firmada' queda como fallback cuando la subida falló o el id venció y
    | todavía no se revalidó: mejor una foto por link que ninguna foto.
    */
    'media' => [
        'entrega'          => 'media_id',
        'fallback'         => 'url_firmada',
        'ttl_dias'         => 30,   // Meta retiene el media_id ~30 días
        'revalidar_antes'  => 3,    // re-subir cuando falten N días para vencer
        'url_firmada_ttl'  => 15,   // minutos de validez del link de fallback
    ],

    /*
    |--------------------------------------------------------------------------
    | Asesor de guardia
    |--------------------------------------------------------------------------
    | A quién derivar cuando el cliente elige "Hablar con un asesor" al principio
    | de la conversación, sin haber hablado de ningún inmueble todavía. Ahí no
    | hay titular que resolver ni lead que consultar.
    |
    | En null, esa derivación devuelve 409 y el agente no puede cumplir lo que
    | acaba de ofrecer en su menú. Ponerle un id es lo que hace que esa opción
    | del menú funcione.
    */
    'asesor_por_defecto' => env('INMOBILIARIA_ASESOR_GUARDIA', 4),  // Sofia (rol Asesor)

    /*
    |--------------------------------------------------------------------------
    | Pipeline donde entran los leads del agente
    |--------------------------------------------------------------------------
    | Se nombra explícitamente en vez de confiar solo en `is_default`: la
    | bandera la puede mover cualquiera desde la UI o un setup mal corrido, y un
    | lead que aparece en el tablero equivocado no se nota hasta que se pierde.
    | Si el nombre no existe, se cae a `is_default` como antes.
    */
    'pipeline' => 'Inmobiliaria C21',

    /*
    |--------------------------------------------------------------------------
    | Escalamiento del handoff
    |--------------------------------------------------------------------------
    | El handoff SIEMPRE va al asesor titular del inmueble (§5 y §8 del handoff),
    | sin excepción ni fallback: derivar a otro rompería la promesa que Sofía
    | acaba de hacerle al cliente ("le paso con <nombre>").
    |
    | La red de contención es una notificación al supervisor si nadie tomó la
    | conversación pasados N minutos. No reasigna: avisa.
    |
    | 'supervisor_user_id' en null = no escala. Setearlo al usuario supervisor.
    |
    | Y la última red: si pasados 'devolver_tras_minutos' el handoff sigue sin
    | que nadie lo tome, la conversación vuelve al agente. Un handoff sin tomar
    | dejaba al cliente mudo indefinidamente, porque mientras estaba vigente el
    | agente ni se enteraba de los mensajes entrantes. Un handoff ya 'tomado'
    | nunca se devuelve: ahí hay un asesor escribiendo y la IA no lo interrumpe.
    */
    'handoff' => [
        'escalar_tras_minutos'  => 15,
        'devolver_tras_minutos' => 60,
        'supervisor_user_id'    => null,
    ],
];
