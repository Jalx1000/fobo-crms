<?php

namespace Tests\Feature;

use Webkul\Admin\Services\SmdStatusMapper;

// Sin DatabaseTransactions — este test es puramente lógico, sin BD

beforeEach(function () {
    $this->mapper = new SmdStatusMapper;

    // Fijar IDs de stage para que los tests no dependan de datos de BD
    config([
        'smd.stage_map.confirmed' => 7,
        'smd.stage_map.completed' => 5,
        'smd.stage_map.cancelled' => 6,
        'smd.stage_map.no_show'   => 6,
    ]);
});

// ---------------------------------------------------------------------------
// normalize()
// ---------------------------------------------------------------------------

it('normalize convierte string vacio a confirmed', function () {
    expect($this->mapper->normalize(''))->toBe('confirmed');
});

it('normalize convierte null a confirmed', function () {
    expect($this->mapper->normalize(null))->toBe('confirmed');
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

it('toLeadStageId devuelve stage ID de confirmed para string vacio', function () {
    expect($this->mapper->toLeadStageId(''))->toBe(7);
});

it('toLeadStageId devuelve stage ID de confirmed para null', function () {
    expect($this->mapper->toLeadStageId(null))->toBe(7);
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
    expect($this->mapper->toLeadStageId('no-show'))->toBe(6);
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

it('isCancelled devuelve true para no-show', function () {
    expect($this->mapper->isCancelled('no-show'))->toBeTrue();
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

it('getTag devuelve No-show para no-show', function () {
    expect($this->mapper->getTag('no-show'))->toBe('No-show');
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
