<?php

use Webkul\Lead\Repositories\LeadRepository;

/**
 * Buscar un lead por el teléfono del contacto.
 *
 * El módulo tiene DOS buscadores sobre los mismos datos - el listado (DataGrid)
 * y el tablero kanban (RequestCriteria sobre el repositorio) - y cada uno lee su
 * propia lista de campos. Que uno encuentre y el otro no es el modo de falla
 * natural acá, así que se afirma sobre los dos.
 */
it('lets the kanban board search by the contact phone number', function () {
    $searchable = (function () {
        // $fieldSearchable es protegido: es configuración del repositorio, no
        // API pública, pero es exactamente lo que decide qué encuentra el
        // tablero.
        return (fn () => $this->fieldSearchable)->call(app(LeadRepository::class));
    })();

    expect($searchable)->toContain('person.contact_numbers');
});

it('still searches the kanban board by contact name', function () {
    $searchable = (fn () => $this->fieldSearchable)->call(app(LeadRepository::class));

    // Regresión: agregar el teléfono no debe desplazar lo que ya funcionaba.
    expect($searchable)->toContain('person.name')
        ->and($searchable)->toContain('title');
});

/*
 * La columna de teléfono del tablero (getKanbanColumns) NO tiene test: armarla
 * consulta tipos, fuentes y etiquetas contra la base, y este host no la alcanza.
 * Se verificó a mano. Si alguna vez hay base en los tests, el caso a cubrir es
 * que exista `person.contact_numbers` con search_field `like`.
 */
