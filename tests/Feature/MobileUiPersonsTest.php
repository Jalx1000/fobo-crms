<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

function fichaPacienteHtml(): string
{
    $person = Person::query()->latest('id')->first();

    return test()->actingAs(User::find(1), 'user')
        ->get(route('admin.contacts.persons.view', $person->id))
        ->assertStatus(200)
        ->getContent();
}

/**
 * Ficha del paciente en el celular, como la de la cita: de borde a borde,
 * el nombre con «←» al listado primero y los datos apilados.
 */
it('la ficha del paciente tiene «←» al listado y va de borde a borde en el celular', function () {
    expect(fichaPacienteHtml())
        ->toContain('aria-label="'.trans('admin::app.layouts.volver').'"')
        ->toContain('max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-0')
        ->toContain('max-lg:-order-1');
});

it('los datos del paciente van con el nombre arriba y el valor debajo en el celular', function () {
    expect(fichaPacienteHtml())
        ->toContain('max-lg:grid-cols-1 max-lg:gap-0.5');
});

/**
 * Un <v-... /> en el HTML de la página no se cierra solo: el componente de
 * seguros se tragaba «Acerca del paciente» y la organización.
 */
it('el componente de seguros se cierra y no se traga «Acerca del paciente»', function () {
    $html = fichaPacienteHtml();

    expect($html)
        ->toContain('</v-person-insurance-statuses>')
        ->not->toMatch('/<v-person-insurance-statuses[^>]*\/>/');

    $cierre = strpos($html, '</v-person-insurance-statuses>');
    $acerca = strpos($html, trans('admin::app.contacts.persons.view.about-person'));

    expect($acerca)->toBeGreaterThan($cierre);
});

/**
 * Lista en el celular, como Citas: buscador y «Filtros» (rango de registro +
 * filtros por columna) en un panel; la barra de fechas y el botón «Filtro»
 * de la tabla quedan solo para la computadora.
 */
it('la lista de pacientes trae el panel «Filtros» y esconde los controles de escritorio en el celular', function () {
    $html = test()->actingAs(User::find(1), 'user')
        ->get(route('admin.contacts.persons.index'))
        ->assertStatus(200)
        ->getContent();

    expect($html)
        ->toContain('<v-persons-filtros-movil></v-persons-filtros-movil>')
        ->toContain('id="v-persons-filtros-movil-template"')
        ->toContain("this.\$emitter.emit('persons:rango-movil', rango);")
        ->toContain("this.\$emitter.on('persons:rango-movil', this.aplicarRangoMovil);")
        ->toContain("this.\$emitter.emit('datagrid:filtros-por-columna');")
        ->toContain('max-lg:[&_.datagrid-filtro-toggle]:hidden')
        ->toContain('mb-4 flex flex-wrap max-lg:hidden')
        ->not->toContain('filtros-movil.ciudad')
        ->not->toContain('Ejecutivo comercial');
});

it('el filtro de la tabla se abre desde el panel del celular y avisa cuántas columnas filtra', function () {
    $filtro = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/components/datagrid/toolbar/filter.blade.php'));

    expect($filtro)
        ->toContain('datagrid-filtro-toggle')
        ->toContain("this.\$emitter.on('datagrid:filtros-por-columna', this.abrirDesdeMovil);")
        ->toContain("this.\$emitter.emit('datagrid:columnas-filtradas', this.getAppliedColumns().length);");
});

it('crear y editar paciente tienen «←» al listado y van de borde a borde', function () {
    $person = Person::query()->latest('id')->first();

    foreach ([route('admin.contacts.persons.create'), route('admin.contacts.persons.edit', $person->id)] as $url) {
        test()->actingAs(User::find(1), 'user')
            ->get($url)
            ->assertStatus(200)
            ->assertSee('aria-label="'.trans('admin::app.layouts.volver').'"', false)
            ->assertSee('max-lg:[&>#smd-result-banner]:-mx-3', false);
    }
});

it('la etiqueta «Registrados» del panel existe en todos los idiomas', function () {
    foreach (['es', 'en', 'pt_BR', 'tr', 'vi', 'ar', 'fa'] as $idioma) {
        expect(trans('admin::app.contacts.persons.index.filtros-movil.registrados', [], $idioma))
            ->not->toBe('admin::app.contacts.persons.index.filtros-movil.registrados');
    }
});
