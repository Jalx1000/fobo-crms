<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Webkul\Admin\Support\SmdTime;

/**
 * SMD publica los eventos en UTC y el CRM agenda en hora boliviana. La
 * conversión estaba repetida en cuatro lugares como
 * `Carbon::parse($x)->setTimezone(config('app.timezone'))`, que es correcto solo
 * mientras el string traiga el offset. Sin offset, Carbon lo interpreta en la
 * zona de la app -que ya es La Paz- y la cita queda 4 horas tarde en silencio.
 */
beforeEach(function () {
    config(['app.timezone' => 'America/La_Paz']);
});

it('convierte una fecha UTC de SMD a hora boliviana', function () {
    // Caso real: evento 6a675da5 - 15:30Z es 11:30 en La Paz.
    expect(SmdTime::toLocalString('2026-07-28T15:30:00.000Z'))->toBe('2026-07-28 11:30:00');
});

it('respeta un offset explícito que no sea Z', function () {
    expect(SmdTime::toLocalString('2026-07-28T15:30:00+02:00'))->toBe('2026-07-28 09:30:00');
});

it('cruza bien el cambio de día', function () {
    // 02:00Z del 29 es todavía el 28 a las 22:00 en Bolivia.
    expect(SmdTime::toLocalString('2026-07-29T02:00:00.000Z'))->toBe('2026-07-28 22:00:00');
});

it('asume UTC cuando la fecha llega sin offset', function () {
    Log::spy();

    expect(SmdTime::toLocalString('2026-07-28T15:30:00'))->toBe('2026-07-28 11:30:00');

    // No puede pasar callado: si SMD cambia el formato hay que enterarse.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($mensaje) => str_contains($mensaje, 'sin offset'));
});

it('devuelve un Carbon en la zona de la clínica', function () {
    $fecha = SmdTime::toLocal('2026-07-28T15:30:00.000Z');

    expect($fecha->timezone->getName())->toBe('America/La_Paz')
        ->and($fecha->hour)->toBe(11);
});

it('no pierde el instante: ida y vuelta a UTC devuelve lo mismo', function () {
    $original = '2026-07-28T15:30:00.000Z';

    expect(SmdTime::toLocal($original)->utc()->format('Y-m-d\TH:i:s.000\Z'))->toBe($original);
});
