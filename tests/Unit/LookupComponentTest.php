<?php

/*
| Buscador genérico (<x-admin::lookup>): pacientes en el calendario, contacto
| del lead, productos. Antes quedaba en «Buscando…» para siempre: comparaba
| this.cancelToken (un Proxy reactivo de Vue) con el objeto original, que nunca
| es ===, así que isSearching no volvía a false. Con resultados no se notaba;
| sin resultados tapaba «Agregar como nuevo».
*/

function lookupView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/lookup/index.blade.php');
}

function lookupTemplate(): string
{
    $view = lookupView();
    $start = strpos($view, 'id="v-lookup-template"');

    return substr($view, $start, strpos($view, '</script>', $start) - $start);
}

it('la búsqueda en curso se identifica por número, no comparando el cancelToken', function () {
    $view = lookupView();

    expect($view)->not->toContain('this.cancelToken === cancelToken')
        ->toContain('lastRequestId: 0,')
        ->toContain('const requestId = ++this.lastRequestId;');

    // Solo la última búsqueda deja resultados y apaga «Buscando…».
    preg_match('/search\(preload = false\) \{(.*?)\n                \},/s', $view, $m);
    expect($m[1])->toMatch('/\.then\(response => \{\s*if \(requestId === this\.lastRequestId\) \{\s*this\.searchedResults = response\.data\.data;/')
        ->toMatch('/\.finally\(\(\) => \{\s*if \(requestId === this\.lastRequestId\) \{\s*this\.isSearching = false;/');
});

it('borrar a menos de 3 letras cancela la búsqueda en curso', function () {
    preg_match('/search\(preload = false\) \{(.*?)\n                \},/s', lookupView(), $m);

    // El id y la cancelación van antes del corte por largo.
    expect(strpos($m[1], '++this.lastRequestId'))->toBeLessThan(strpos($m[1], 'this.searchTerm.length <= 2'))
        ->and(strpos($m[1], 'this.cancelToken.cancel();'))->toBeLessThan(strpos($m[1], 'this.searchTerm.length <= 2'));
});

it('«Agregar como nuevo» aparece también cuando hay coincidencias', function () {
    $tpl = lookupTemplate();

    expect($tpl)->toContain('v-if="canAddNew && searchTerm.length > 2 && ! isSearching"')
        ->toContain("@lang('admin::app.components.lookup.add-as-new'): «@{{ searchTerm }}»")
        // Ya no está metido dentro del bloque de «sin resultados».
        ->not->toContain('<template v-else-if="filteredResults.length === 0">');
});

it('con menos de 3 letras avisa el mínimo en vez de «sin resultados»', function () {
    $tpl = lookupTemplate();

    expect($tpl)->toContain('v-else-if="filteredResults.length === 0 && ! preload && searchTerm.length <= 2"')
        ->toContain('Escribe al menos 3 letras para buscar.')
        ->and(strpos($tpl, 'Escribe al menos 3 letras'))->toBeLessThan(strpos($tpl, 'lookup.no-results'));
});
