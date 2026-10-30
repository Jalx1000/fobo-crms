<?php

namespace Tests\Feature;

use Webkul\User\Models\User;

/**
 * Tablero en el celular, como Citas: banda con «Tablero», el período y
 * «Filtros»; los controles de fecha en un panel lateral; tarjetas de borde a
 * borde y totales de a dos. En la computadora no cambia nada (todo va con
 * «max-lg:» o «lg:hidden»).
 */
function tableroHtml(): string
{
    return test()->actingAs(User::find(1), 'user')
        ->get(route('admin.dashboard.index'))
        ->assertOk()
        ->getContent();
}

it('el tablero lleva la banda de borde a borde y el botón «Filtros» solo en el celular', function () {
    expect(tableroHtml())
        ->toContain('max-lg:-mx-3 max-lg:-mt-3 max-lg:mb-0 max-lg:border-b')
        ->toContain('<div class="flex items-center gap-2 lg:hidden">')
        ->toContain('v-text="resumenPeriodo"')
        ->toContain('@click="filtrosAbiertos = true"');
});

it('los filtros de fecha van en un panel lateral en el celular y en línea en la computadora', function () {
    expect(tableroHtml())
        ->toContain('role="dialog"')
        ->toContain(":class=\"filtrosAbiertos ? 'max-lg:translate-x-0' : 'max-lg:translate-x-full'\"")
        ->toContain('max-lg:fixed max-lg:inset-y-0 max-lg:right-0')
        ->toContain('Ver tablero');
});

it('las columnas del tablero se encogen y las tarjetas van de borde a borde', function () {
    expect(tableroHtml())
        ->toContain('flex min-w-0 flex-1 flex-col gap-4 max-xl:flex-auto max-xl:basis-full max-lg:gap-2')
        ->toContain('flex w-[378px] min-w-0 max-w-full flex-col gap-4 max-xl:w-full max-lg:gap-2')
        ->toContain('max-lg:[&>div>*]:rounded-none max-lg:[&>div>*]:border-x-0');
});

it('los totales van de a cuatro en la computadora y de a dos en el celular', function () {
    expect(tableroHtml())
        ->toContain('grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-lg:gap-2 max-lg:px-3')
        ->not->toContain('grid-template-columns: repeat(4, minmax(0, 1fr))');
});
