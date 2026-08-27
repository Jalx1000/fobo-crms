<?php

/**
 * Real-estate master data for this instance. Data only — no logic lives here.
 *
 * Read by Webkul\Inmobiliaria\Support\CatalogoSeeder. Changing what gets seeded
 * should never mean changing code.
 *
 * Conventions that the seeder depends on:
 *
 * - Pipelines are matched by `name` (lead_pipelines.name is UNIQUE).
 * - Stages are matched by `code` within their pipeline
 *   (lead_pipeline_stages has UNIQUE(code, lead_pipeline_id)), so the same code
 *   may repeat across pipelines. Never match a stage by name: the client can
 *   rename it from the UI at any time.
 * - Every pipeline MUST end in the `won` and `lost` codes. The core detects a
 *   closed lead with in_array($stage->code, ['won','lost']) in three separate
 *   places; without them closed_at is never set.
 * - `rotten_days` is per PIPELINE, not per stage — the schema has no column for
 *   the latter. Each value below is the longest a lead may legitimately sit in
 *   any one of that pipeline's stages. See decision D1 in the spec.
 * - No attribute is ever is_required: a required attribute on one pipeline
 *   would block lead creation on the other two, since attribute scope is per
 *   entity_type, not per pipeline.
 */
return [
    /*
     * The pipeline whose name matches this becomes is_default. Applied as a
     * separate final step: PipelineRepository::create() clears the flag on every
     * other pipeline, so creating them in sequence with the flag set would let
     * the last one win.
     */
    /*
     * C21 opera con UN pipeline, `Inmobiliaria C21`, creado y ordenado a mano
     * por la oficina — no está en este catálogo. Los tres pipelines de abajo
     * quedaron sin uso tras esa decisión.
     *
     * Esto apuntaba a 'Venta'. Cambiarlo importa: correr el setup con el valor
     * viejo le sacaba el is_default al pipeline que la oficina usa de verdad, y
     * el alta de leads del agente resuelve el pipeline por esa bandera.
     */
    'pipeline_por_defecto' => 'Inmobiliaria C21',

    'pipelines' => [
        [
            'name'        => 'Venta',
            'rotten_days' => 30,
            'stages'      => [
                ['code' => 'nuevo',            'name' => 'Nuevo',            'probability' => 5],
                ['code' => 'calificando',      'name' => 'Calificando',      'probability' => 15],
                ['code' => 'con-match',        'name' => 'Con match',        'probability' => 25],
                ['code' => 'visita-agendada',  'name' => 'Visita agendada',  'probability' => 40],
                ['code' => 'visita-realizada', 'name' => 'Visita realizada', 'probability' => 55],
                ['code' => 'propuesta',        'name' => 'Propuesta',        'probability' => 75],
                ['code' => 'documentacion',    'name' => 'Documentación',    'probability' => 90],
                ['code' => 'won',              'name' => 'Ganado',           'probability' => 100],
                ['code' => 'lost',             'name' => 'Perdido',          'probability' => 0],
            ],
        ],
        [
            'name'        => 'Alquiler y anticrético',
            'rotten_days' => 5,
            'stages'      => [
                ['code' => 'nuevo',           'name' => 'Nuevo',           'probability' => 10],
                ['code' => 'calificando',     'name' => 'Calificando',     'probability' => 25],
                ['code' => 'con-match',       'name' => 'Con match',       'probability' => 40],
                ['code' => 'visita-agendada', 'name' => 'Visita agendada', 'probability' => 60],
                ['code' => 'requisitos',      'name' => 'Requisitos',      'probability' => 80],
                ['code' => 'contrato',        'name' => 'Contrato',        'probability' => 92],
                ['code' => 'won',             'name' => 'Ganado',          'probability' => 100],
                ['code' => 'lost',            'name' => 'Perdido',         'probability' => 0],
            ],
        ],
        [
            'name'        => 'Captación',
            'rotten_days' => 7,
            'stages'      => [
                ['code' => 'propietario-nuevo',  'name' => 'Propietario nuevo',  'probability' => 10],
                ['code' => 'datos-relevados',    'name' => 'Datos relevados',    'probability' => 25],
                ['code' => 'relevamiento',       'name' => 'Relevamiento',       'probability' => 45],
                ['code' => 'propuesta-de-valor', 'name' => 'Propuesta de valor', 'probability' => 65],
                ['code' => 'autorizacion',       'name' => 'Autorización',       'probability' => 85],
                ['code' => 'publicado',          'name' => 'Publicado',          'probability' => 100],
                ['code' => 'won',                'name' => 'Ganado',             'probability' => 100],
                ['code' => 'lost',               'name' => 'Perdido',            'probability' => 0],
            ],
        ],
    ],

    /*
     * "Web" already exists on this instance (lead_sources.id = 2). The seeder
     * skips it, and the rollback must never delete it — which is exactly why
     * the rollback works off the manifest of what it actually created, not off
     * this list.
     */
    'sources' => [
        'WhatsApp orgánico',
        'Anuncio Meta',
        'Anuncio Google',
        'Web',
        'Portal inmobiliario',
        'Referido',
        'Feria',
        'Cartel en obra',
        'Llamada',
    ],

    'types' => [
        'Comprador',
        'Inquilino',
        'Propietario',
        'Inversionista',
        'Referidor',
        // Distingue el flujo de postventa dentro del pipeline único: C21 decidió
        // no tener un tablero aparte, así que el tipo es lo que separa una
        // operación en curso de una búsqueda nueva.
        'Postventa',
    ],

    /*
     * All on entity_type = leads, all optional, all user-defined.
     *
     * Prefixes group them by purpose because attribute scope is per entity and
     * the three pipelines therefore share one form:
     *   op_      operation / qualification
     *   bot_     written by the WhatsApp AI agent through n8n
     *   vis_     viewing
     *   perdida_ loss reason
     *   cap_     listing acquisition
     *
     * On types: Krayin has no numeric attribute type. The only non-select route
     * to a numeric column is `price` -> float_value, and `price` makes the form
     * append a currency symbol to the label. op_dormitorios and bot_puntaje are
     * therefore `text` with validation => 'numeric' (decision D5). Consequence:
     * they live in text_value, so n8n must CAST to compare by range, and
     * ORDER BY is lexicographic — which matters for bot_puntaje (0-100).
     */
    'attributes' => [
        ['code' => 'op_modalidad', 'name' => 'Modalidad', 'type' => 'select', 'options' => [
            'venta', 'alquiler', 'anticrético', 'preventa',
        ]],
        ['code' => 'op_tipo_inmueble', 'name' => 'Tipo de inmueble', 'type' => 'select', 'options' => [
            'departamento', 'casa', 'terreno', 'local comercial',
            'oficina', 'galpón', 'quinta', 'propiedad agrícola',
        ]],
        ['code' => 'op_zona', 'name' => 'Zona', 'type' => 'text'],
        ['code' => 'op_presupuesto_min', 'name' => 'Presupuesto mínimo', 'type' => 'price'],
        ['code' => 'op_presupuesto_max', 'name' => 'Presupuesto máximo', 'type' => 'price'],
        ['code' => 'op_moneda', 'name' => 'Moneda', 'type' => 'select', 'options' => [
            'USD', 'BOB',
        ]],
        ['code' => 'op_dormitorios', 'name' => 'Dormitorios mínimos', 'type' => 'text', 'validation' => 'numeric'],
        ['code' => 'op_forma_pago', 'name' => 'Forma de pago', 'type' => 'select', 'options' => [
            'contado', 'crédito bancario', 'crédito VIS', 'plan del desarrollador',
        ]],
        ['code' => 'op_banco', 'name' => 'Banco', 'type' => 'text'],
        ['code' => 'op_uso', 'name' => 'Uso', 'type' => 'select', 'options' => [
            'vivir', 'inversión', 'renta', 'negocio',
        ]],
        ['code' => 'op_plazo', 'name' => 'Plazo de decisión', 'type' => 'select', 'options' => [
            'hasta 30 días', '1 a 3 meses', '3 a 6 meses', 'sin definir',
        ]],

        // Deduplication key for the agent. Deliberately NOT is_unique: one
        // person legitimately has several leads (a purchase and a rental), and
        // a unique constraint here would block the second one.
        ['code' => 'bot_wa_id', 'name' => 'WhatsApp ID', 'type' => 'text'],
        ['code' => 'bot_puntaje', 'name' => 'Puntaje', 'type' => 'text', 'validation' => 'numeric'],
        ['code' => 'bot_temperatura', 'name' => 'Temperatura', 'type' => 'select', 'options' => [
            'caliente', 'tibio', 'frío',
        ]],
        ['code' => 'bot_codigos_vistos', 'name' => 'Inmuebles mostrados', 'type' => 'textarea'],
        ['code' => 'bot_pausado_hasta', 'name' => 'Bot pausado hasta', 'type' => 'datetime'],

        ['code' => 'vis_codigo', 'name' => 'Inmueble de la visita', 'type' => 'text'],
        ['code' => 'vis_asesor_titular', 'name' => 'Asesor titular', 'type' => 'text'],
        ['code' => 'vis_resultado', 'name' => 'Resultado de la visita', 'type' => 'select', 'options' => [
            'le gustó', 'no le gustó', 'no asistió', 'pendiente',
        ]],

        ['code' => 'perdida_motivo', 'name' => 'Motivo de pérdida', 'type' => 'select', 'options' => [
            'sin presupuesto real',
            'no calificó al crédito',
            'compró o alquiló con otra inmobiliaria',
            'zona sin cartera',
            'precio fuera de mercado',
            'no aceptó requisitos o garantía',
            'documentación trabada',
            'nunca respondió',
            'solo consultaba',
            'no es el titular',
            'no aceptó exclusividad',
            'duplicado',
        ]],

        ['code' => 'cap_precio_esperado', 'name' => 'Precio esperado', 'type' => 'price'],
        ['code' => 'cap_estado_documental', 'name' => 'Estado documental', 'type' => 'select', 'options' => [
            'folio real limpio', 'en regularización', 'solo minuta', 'preventa',
        ]],
        ['code' => 'cap_exclusividad', 'name' => 'Exclusividad', 'type' => 'boolean'],
    ],
];
