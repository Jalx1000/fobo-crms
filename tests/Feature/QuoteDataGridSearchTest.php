<?php

use Webkul\Admin\DataGrids\Quote\QuoteDataGrid;

/**
 * La caja de busqueda del listado de Cotizaciones.
 *
 * Se afirma sobre la CONFIGURACION de columnas, no sobre filas: el host de
 * desarrollo no llega a la base. Y alcanza, porque el bug que esto cubre vive
 * exactamente ahi - `processRequestedFilters()` recorre las columnas
 * `searchable` y, si no hay ninguna, arma un `where(function () {})` vacio: la
 * busqueda no falla, devuelve la grilla entera. Silencioso y peor que un error.
 */
function quoteColumns(): Illuminate\Support\Collection
{
    $grid = new QuoteDataGrid;

    $grid->prepareColumns();

    return collect($grid->getColumns())->keyBy(fn ($column) => $column->getIndex());
}

it('searches by contact name from the search box', function () {
    expect(quoteColumns()->get('person_name')->getSearchable())->toBeTrue();
});

it('searches by the contact phone number', function () {
    expect(quoteColumns()->get('contact_numbers'))->not->toBeNull()
        ->and(quoteColumns()->get('contact_numbers')->getSearchable())->toBeTrue();
});

it('has at least one searchable column, or the search box matches everything', function () {
    $searchable = quoteColumns()->filter(fn ($column) => $column->getSearchable());

    expect($searchable)->not->toBeEmpty();
});

it('keeps the channel column out of the free-text search', function () {
    // `platform` es una expresion COALESCE, no una columna: meterla en el LIKE
    // de la busqueda libre romperia el SQL en vez de no encontrar nada.
    expect(quoteColumns()->get('platform')->getSearchable())->toBeFalse();
});
