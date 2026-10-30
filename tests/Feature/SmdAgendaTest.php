<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Admin\Services\Smd\SmdAgenda;

/**
 * Una consulta a SMD con "General" trae la agenda de todos los doctores, y Sofo
 * filtra lo que necesita (planning/16-especialidades-en-crm, RF-1 a RF-3, RF-6,
 * RF-11). Las fechas son de 2031 para no pisar la caché real de Redis.
 */

/** Bloques de 15 min libres entre $from y $to, en el formato de SMD (groupByDay). */
function agendaSlots(string $date, string $from, string $to): array
{
    $blocks = [];

    for ($c = Carbon::parse("{$date} {$from}", 'America/La_Paz'); $c->lt(Carbon::parse("{$date} {$to}", 'America/La_Paz')); $c->addMinutes(15)) {
        $blocks[] = ['start' => $c->format('Y-m-d\TH:i:sP'), 'end' => $c->copy()->addMinutes(15)->format('Y-m-d\TH:i:sP')];
    }

    return [[$date => $blocks]];
}

function agendaPhysician(string $id, array $slots): array
{
    return ['physician' => ['_id' => $id, 'name' => 'Doc', 'lastName' => $id], 'slots' => $slots];
}

beforeEach(function () {
    $this->start = Carbon::parse('2031-03-03', 'America/La_Paz');
    Cache::forget('smd:agenda:2031-03-03:7');
    Cache::forget('smd:agenda:2031-03-03:1');
});

afterEach(function () {
    Cache::forget('smd:agenda:2031-03-03:7');
    Cache::forget('smd:agenda:2031-03-03:1');
});

it('agenda() hace una sola consulta, siempre con la especialidad General', function () {
    config(['smd.availability_specialty' => 'General']);
    Http::fake(['*' => Http::response([], 200)]);

    app(ShareMeDataService::class)->agenda('2031-03-03 00:00:00', '2031-03-09 23:59:59');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $query = urldecode($request->url());

        return str_contains($query, '/schedule/availability')
            && str_contains($query, 'specialty=General')
            && ! str_contains($query, 'Ortodoncia');
    });
});

it('agenda() nunca manda fechas sin hora (SMD toma el `to` sin hora como exclusivo)', function () {
    Http::fake(['*' => Http::response([], 200)]);

    app(ShareMeDataService::class)->agenda('2031-03-03 00:00:00', '2031-03-09 23:59:59');

    Http::assertSent(fn (Request $request) => ($request->data()['from'] ?? null) === '2031-03-03T00:00:00-04:00'
        && ($request->data()['to'] ?? null) === '2031-03-09T23:59:59-04:00');
});

it('la agenda de un rango se pide una vez y la comparten todos', function () {
    Http::fake(['*' => Http::response([
        agendaPhysician('doc-a', agendaSlots('2031-03-03', '09:00', '12:00')),
        agendaPhysician('doc-b', agendaSlots('2031-03-04', '14:00', '15:00')),
    ], 200)]);
    $agenda = app(SmdAgenda::class);

    $todos = $agenda->all($this->start, 7);
    $docA = $agenda->forDoctor('doc-a', $this->start, 7);
    $docB = $agenda->forDoctor('doc-b', $this->start, 7);

    Http::assertSentCount(1);
    expect(array_keys($todos))->toBe(['doc-a', 'doc-b'])
        ->and($docA[0]['2031-03-03'])->toHaveCount(12)
        ->and($docB[0]['2031-03-04'])->toHaveCount(4);
});

it('un médico que SMD no publica no tiene bloques', function () {
    Http::fake(['*' => Http::response([agendaPhysician('doc-a', agendaSlots('2031-03-03', '09:00', '10:00'))], 200)]);

    expect(app(SmdAgenda::class)->forDoctor('no-existe', $this->start, 7))->toBe([]);
});

it('si SMD falla no se guarda nada y se informa con null', function () {
    Http::fake(['*' => Http::response(['error' => 'x'], 500)]);
    $agenda = app(SmdAgenda::class);

    expect($agenda->all($this->start, 7))->toBeNull()
        ->and(Cache::has('smd:agenda:2031-03-03:7'))->toBeFalse();
});

it('un horario está libre solo si lo están todos sus bloques de 15 min', function () {
    // Libre de 10:00 a 12:00, pero 11:00–12:00 está ocupado (ejemplo de Javier).
    $slots = agendaSlots('2031-03-03', '10:00', '11:00');
    $at = fn (string $h) => Carbon::parse("2031-03-03 {$h}", 'America/La_Paz');

    expect(SmdAgenda::covers($slots, $at('10:00'), $at('11:00')))->toBeTrue()
        ->and(SmdAgenda::covers($slots, $at('10:30'), $at('11:00')))->toBeTrue()
        ->and(SmdAgenda::covers($slots, $at('10:00'), $at('12:00')))->toBeFalse()
        ->and(SmdAgenda::covers($slots, $at('11:00'), $at('11:30')))->toBeFalse();
});

it('confirmar una cita consulta SMD en el momento, sin usar la agenda guardada', function () {
    Http::fake(['*' => Http::response([agendaPhysician('doc-a', agendaSlots('2031-03-03', '09:00', '10:00'))], 200)]);
    $agenda = app(SmdAgenda::class);
    $at = fn (string $h) => Carbon::parse("2031-03-03 {$h}", 'America/La_Paz');

    $agenda->all($this->start, 7);
    $libre = $agenda->isAvailable('doc-a', $at('09:00'), $at('10:00'));
    $otro = $agenda->isAvailable('doc-b', $at('09:00'), $at('10:00'));

    Http::assertSentCount(3);
    expect($libre)->toBeTrue()->and($otro)->toBeFalse();
});

it('confirmar una cita con SMD caído devuelve null, no "ocupado"', function () {
    Http::fake(['*' => Http::response([], 503)]);
    $at = fn (string $h) => Carbon::parse("2031-03-03 {$h}", 'America/La_Paz');

    expect(app(SmdAgenda::class)->isAvailable('doc-a', $at('09:00'), $at('10:00')))->toBeNull();
});
