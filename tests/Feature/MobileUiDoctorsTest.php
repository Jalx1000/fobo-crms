<?php

namespace Tests\Feature;

use Webkul\Doctor\Models\Doctor;
use Webkul\User\Models\User;

/**
 * Doctores, Especialidades y Turnos en el celular: de borde a borde, «←» al
 * listado y botones de la cabecera que bajan de renglón en vez de salirse.
 */
function paginaDoctores(string $ruta): string
{
    return test()->actingAs(User::find(1), 'user')->get($ruta)->assertOk()->getContent();
}

it('los listados de doctores, especialidades y turnos van de borde a borde', function () {
    foreach (['admin.doctor.index', 'admin.specialties.index', 'admin.schedules.index'] as $listado) {
        expect(paginaDoctores(route($listado)))
            ->toContain('max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none');
    }

    expect(paginaDoctores(route('admin.specialties.index')))
        ->toContain('flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2');
});

it('los formularios de doctor, especialidad y turno tienen «←» al listado', function () {
    $volver = 'aria-label="'.trans('admin::app.layouts.volver').'"';

    $rutas = [route('admin.doctor.create'), route('admin.specialties.create'), route('admin.schedules.create')];

    if ($doctor = Doctor::query()->latest('id')->first()) {
        $rutas[] = route('admin.doctor.edit', $doctor->id);
    }

    foreach ($rutas as $ruta) {
        expect(paginaDoctores($ruta))
            ->toContain($volver)
            ->toContain('max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none');
    }

    // En la especialidad «Volver al listado» se oculta en el celular: ya está la «←».
    expect(paginaDoctores(route('admin.specialties.create')))
        ->toContain('class="secondary-button max-lg:hidden"');
});

it('el CSS del Admin incluye las clases de las vistas de Doctores', function () {
    // Sin esto, «Notas» (col-span-2 max-lg:col-span-1) armaba una 2.ª columna en el turno.
    expect(file_get_contents(base_path('packages/Webkul/Admin/tailwind.config.js')))
        ->toContain('../Doctor/src/Resources/**/*.blade.php');
});
