<?php

namespace Tests\Feature;

use Webkul\Product\Models\Product;
use Webkul\User\Models\User;

/**
 * Productos (Servicios) en el celular, como Citas y Pacientes: de borde a
 * borde, «←» al listado y columnas que no se estiran más que la pantalla.
 */
function paginaProductos(string $ruta): string
{
    return test()->actingAs(User::find(1), 'user')->get($ruta)->assertOk()->getContent();
}

it('la lista de servicios va de borde a borde en el celular', function () {
    expect(paginaProductos(route('admin.products.index')))
        ->toContain('max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none')
        ->toContain('max-lg:[&_.datagrid-toolbar]:px-3');
});

it('crear, editar y ver servicio tienen «←» al listado y columnas que se encogen', function () {
    $product = Product::query()->latest('id')->first();

    if (! $product) {
        $this->markTestSkipped('No hay servicios.');
    }

    $volver = 'aria-label="'.trans('admin::app.layouts.volver').'"';

    foreach ([route('admin.products.create'), route('admin.products.edit', $product->id)] as $ruta) {
        expect(paginaProductos($ruta))
            ->toContain($volver)
            ->toContain('flex min-w-0 flex-1 flex-col gap-2 max-xl:flex-auto max-xl:basis-full')
            ->toContain('flex w-[360px] min-w-0 max-w-full flex-col gap-2');
    }

    expect(paginaProductos(route('admin.products.view', $product->id)))
        ->toContain($volver)
        ->toContain('mb-2 flex flex-col gap-0.5 max-lg:-order-1')
        ->toContain('flex w-full min-w-0 flex-col gap-4 rounded-lg');
});

it('el historial corta los valores largos sin espacios en vez de ensanchar la ficha', function () {
    $componente = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/components/activities/index.blade.php'));

    expect($componente)
        ->toContain('class="flex w-full min-w-0 justify-between gap-4 rounded-md p-4"')
        ->toContain('<div class="flex min-w-0 flex-col gap-2 [overflow-wrap:anywhere]">')
        ->toContain('<p class="flex flex-wrap items-center gap-1">');
});
