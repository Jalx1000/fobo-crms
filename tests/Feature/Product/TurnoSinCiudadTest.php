<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Product\Services\Responsables;
use Webkul\User\Models\User;

/**
 * Sin asesor por defecto (13/10): lo que llega sin ciudad se reparte por turno
 * entre los ejecutivos «sin ciudad» (cobertura Bolivia), menos los que están
 * fuera del turno (Mariano).
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();

    config([
        'cotizacion.asesor_por_defecto' => null,
        'cotizacion.turno_sin_ciudad'   => true,
        'cotizacion.fuera_del_turno'    => [$this->mariano->email],
    ]);
});

it('reparte por turno entre los ejecutivos sin ciudad, sin Mariano', function () {
    $r = app(Responsables::class);

    $nombres = collect(range(1, 4))->map(fn () => $r->asesorPorDefecto()?->name);

    expect($nombres)->not->toContain('Mariano Pinell')
        // Desde el 18/10/2026 el único nacional es Bulmaro (Eduardo: solo Magia Verde en Santa Cruz).
        ->and($nombres->unique()->values()->all())->toBe(['Bulmaro Vaca']);
});

it('sin ciudad conocida, porCiudad también usa el turno', function () {
    expect(app(Responsables::class)->porCiudad(null)?->name)->toBeIn(['Bulmaro Vaca', 'Eduardo Lujan']);
});

it('un asesor por defecto configurado sigue mandando', function () {
    $bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    config(['cotizacion.asesor_por_defecto' => $bulmaro->email]);

    expect(app(Responsables::class)->asesorPorDefecto()?->id)->toBe($bulmaro->id);
});
