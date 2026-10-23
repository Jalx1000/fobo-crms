<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

function kanbanHtml(): string
{
    return test()->actingAs(User::find(1), 'user')
        ->get(route('admin.leads.index'))
        ->assertStatus(200)
        ->getContent();
}

/**
 * Kanban en el celular: una etapa por pantalla con pestañas, y el resto de
 * los filtros (embudo, fechas, columnas, vista) en el panel «Filtros».
 */
it('el kanban trae las pestañas de etapas y el panel «Filtros» con el rango propio', function () {
    expect(kanbanHtml())
        ->toContain('role="tablist"')
        ->toContain('@click="irAEtapa(posicion)"')
        ->toContain('ref="filtrosMovil"')
        ->toContain('applyQuick(range.value); $refs.filtrosMovil.close()')
        ->toContain('v-model="applied.dateFrom"')
        ->toContain('@click="abrirFiltrosPorColumna"')
        ->toContain(route('admin.leads.index', ['view_type' => 'table', 'pipeline_id' => null]));
});

it('el panel «Filtros» no trae ciudad, ejecutivo ni plataforma', function () {
    expect(kanbanHtml())
        ->not->toContain('filtros-movil.ciudad')
        ->not->toContain('Ejecutivo comercial')
        ->not->toContain('Plataforma');
});

/**
 * «Mover» cambia la etapa sin arrastrar, por el mismo camino que el
 * arrastre (handleUpdate), y un error de red avisa en vez de romperse.
 */
it('cada tarjeta tiene «Mover» y usa el mismo handleUpdate que el arrastre', function () {
    expect(kanbanHtml())
        ->toContain('@click.prevent.stop="abrirMover(element, stage)"')
        ->toContain('this.handleUpdate(desde, { removed: { element: lead } });')
        ->toContain('this.handleUpdate(destino, { added: { element: lead } });')
        ->toContain('error.response?.data?.message ??');

    // Sin respuesta (red caída) el aviso no puede romperse.
    expect(file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/leads/index/kanban.blade.php')))
        ->not->toContain('error.response.data.message');
});

it('crear, editar y ver una cita tienen «←» al listado', function () {
    $lead = Lead::query()->latest('id')->first();

    $admin = User::find(1);

    foreach ([route('admin.leads.create'), route('admin.leads.edit', $lead->id), route('admin.leads.view', $lead->id)] as $url) {
        $this->actingAs($admin, 'user')
            ->get($url)
            ->assertStatus(200)
            ->assertSee('aria-label="'.trans('admin::app.layouts.volver').'"', false);
    }
});

it('la traducción del error de conexión existe en todos los idiomas', function () {
    foreach (['es', 'en', 'pt_BR', 'tr', 'vi', 'ar', 'fa'] as $idioma) {
        expect(trans('admin::app.leads.index.kanban.error-conexion', [], $idioma))
            ->not->toBe('admin::app.leads.index.kanban.error-conexion');
    }
});
