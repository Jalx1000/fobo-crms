<?php

namespace Tests\Feature;

use Webkul\Admin\Services\SmdStatusMapper;

// Sin DatabaseTransactions - este test es puramente lógico, sin BD

beforeEach(function () {
    $this->mapper = new SmdStatusMapper;

    // Fijar IDs de stage para que los tests no dependan de datos de BD
    config([
        'smd.stage_map.confirmed' => 7,
        'smd.stage_map.completed' => 5,
        'smd.stage_map.cancelled' => 6,
        'smd.stage_map.no_show'   => 8,
        'smd.stage_map.surgery'   => 10,
        'smd.stage_map.default'   => 1,
    ]);
});

// ---------------------------------------------------------------------------
// normalize()
// ---------------------------------------------------------------------------

// normalize() NO inventa un status: vacío se deja vacío. Es el valor más
// frecuente que manda SMD y por decisión de negocio va a Consultas, no a Cita
// confirmada - asumirlo "confirmed" daba por confirmadas citas que nadie
// confirmó.
it('normalize deja el string vacio como vacio', function () {
    expect($this->mapper->normalize(''))->toBe('');
});

it('normalize convierte null en string vacio', function () {
    expect($this->mapper->normalize(null))->toBe('');
});

it('normalize convierte CANCELED a canceled (lowercase)', function () {
    expect($this->mapper->normalize('CANCELED'))->toBe('canceled');
});

it('normalize conserva confirmed en lowercase', function () {
    expect($this->mapper->normalize('confirmed'))->toBe('confirmed');
});

it('normalize convierte no-show a lowercase', function () {
    expect($this->mapper->normalize('no-show'))->toBe('no-show');
});

it('normalize convierte NO-SHOW mayusculas a lowercase', function () {
    expect($this->mapper->normalize('NO-SHOW'))->toBe('no-show');
});

it('normalize recorta espacios antes de normalizar', function () {
    expect($this->mapper->normalize('  CANCELED  '))->toBe('canceled');
});

// ---------------------------------------------------------------------------
// toLeadStageId()
// ---------------------------------------------------------------------------

it('toLeadStageId manda el status vacio a la etapa default (Consultas)', function () {
    expect($this->mapper->toLeadStageId(''))->toBe(1);
});

it('toLeadStageId manda null a la etapa default (Consultas)', function () {
    expect($this->mapper->toLeadStageId(null))->toBe(1);
});

it('toLeadStageId devuelve stage ID de surgery para SURGERY', function () {
    expect($this->mapper->toLeadStageId('SURGERY'))->toBe(10);
});

it('toLeadStageId devuelve stage ID de no_show para MISSED', function () {
    expect($this->mapper->toLeadStageId('MISSED'))->toBe(8);
});

it('toLeadStageId devuelve stage ID de cancelled para CANCELED', function () {
    expect($this->mapper->toLeadStageId('CANCELED'))->toBe(6);
});

it('toLeadStageId devuelve stage ID de cancelled para CANCELLED', function () {
    expect($this->mapper->toLeadStageId('CANCELLED'))->toBe(6);
});

it('toLeadStageId devuelve stage ID de completed para completed', function () {
    expect($this->mapper->toLeadStageId('completed'))->toBe(5);
});

it('toLeadStageId devuelve null para status desconocido', function () {
    expect($this->mapper->toLeadStageId('unknown_value'))->toBeNull();
});

it('toLeadStageId devuelve stage ID de no_show para no-show', function () {
    expect($this->mapper->toLeadStageId('no-show'))->toBe(8);
});

// ---------------------------------------------------------------------------
// isCancelled()
// ---------------------------------------------------------------------------

it('isCancelled devuelve true para CANCELED', function () {
    expect($this->mapper->isCancelled('CANCELED'))->toBeTrue();
});

it('isCancelled devuelve true para CANCELLED', function () {
    expect($this->mapper->isCancelled('CANCELLED'))->toBeTrue();
});

// "missed"/"no-show" NO son cancelaciones: la cita existió y el paciente faltó,
// así que debe crearse el lead en "Cita Perdida". Cuando caían en el mismo grupo
// que las canceladas, un sync desde cero las archivaba sin lead y la cita perdida
// nunca aparecía en el CRM.
it('isCancelled devuelve false para no-show', function () {
    expect($this->mapper->isCancelled('no-show'))->toBeFalse();
});

it('isCancelled devuelve false para MISSED', function () {
    expect($this->mapper->isCancelled('MISSED'))->toBeFalse();
});

it('isCancelled devuelve false para SURGERY', function () {
    expect($this->mapper->isCancelled('SURGERY'))->toBeFalse();
});

it('isCancelled devuelve false para confirmed', function () {
    expect($this->mapper->isCancelled('confirmed'))->toBeFalse();
});

it('isCancelled devuelve false para string vacio', function () {
    expect($this->mapper->isCancelled(''))->toBeFalse();
});

it('isCancelled devuelve false para null', function () {
    expect($this->mapper->isCancelled(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// getTag()
// ---------------------------------------------------------------------------

it('getTag devuelve Cancelado para CANCELED', function () {
    expect($this->mapper->getTag('CANCELED'))->toBe('Cancelado');
});

it('getTag devuelve Cancelado para CANCELLED', function () {
    expect($this->mapper->getTag('CANCELLED'))->toBe('Cancelado');
});

it('getTag devuelve Cita perdida para no-show', function () {
    expect($this->mapper->getTag('no-show'))->toBe('Cita perdida');
});

it('getTag devuelve Cita perdida para MISSED', function () {
    expect($this->mapper->getTag('MISSED'))->toBe('Cita perdida');
});

it('getTag devuelve Cirugía para SURGERY', function () {
    expect($this->mapper->getTag('SURGERY'))->toBe('Cirugía');
});

// managedTags() tiene que cubrir TODO lo que getTag() puede devolver: es la
// lista que usa la sincronización para sacar el tag anterior cuando cambia el
// status. Si se agrega un tag nuevo y no se lo suma acá, queda pegado al lead.
it('managedTags cubre todos los tags que getTag puede devolver', function () {
    $emitidos = collect(['CANCELED', 'CANCELLED', 'no-show', 'MISSED', 'SURGERY', 'confirmed', 'completed', ''])
        ->map(fn ($status) => $this->mapper->getTag($status))
        ->filter()
        ->unique()
        ->values()
        ->all();

    expect($emitidos)->not->toBeEmpty()
        ->and(array_diff($emitidos, $this->mapper->managedTags()))->toBeEmpty();
});

it('getTagColor devuelve un hex para cada status con tag', function () {
    expect($this->mapper->getTagColor('CANCELED'))->toBe('#ef4444')
        ->and($this->mapper->getTagColor('MISSED'))->toBe('#f97316')
        ->and($this->mapper->getTagColor('SURGERY'))->toBe('#8b5cf6');
});

it('getTag devuelve null para confirmed', function () {
    expect($this->mapper->getTag('confirmed'))->toBeNull();
});

it('getTag devuelve null para string vacio', function () {
    expect($this->mapper->getTag(''))->toBeNull();
});

it('getTag devuelve null para null', function () {
    expect($this->mapper->getTag(null))->toBeNull();
});
