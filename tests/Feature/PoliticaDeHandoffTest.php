<?php

use Webkul\Whatsapp\Models\Handoff;
use Webkul\Whatsapp\Services\PoliticaDeHandoff;

/*
| Sin tocar la base: la política es una decisión pura sobre un handoff, y el
| bug que motiva estos tests (conversaciones mudas para siempre) se reproduce
| con solo un state y un opened_at.
*/

beforeEach(function () {
    config()->set('inmobiliaria.inmuebles.handoff.devolver_tras_minutos', 60);

    $this->politica = new PoliticaDeHandoff;

    $this->handoff = function (string $state, int $minutosAtras): Handoff {
        $handoff = new Handoff;
        $handoff->state = $state;
        $handoff->opened_at = now()->subMinutes($minutosAtras);

        return $handoff;
    };
});

it('devuelve al agente un handoff que nadie tomó pasado el plazo', function () {
    expect($this->politica->debeDevolverAlAgente(($this->handoff)('abierto', 61)))->toBeTrue();
});

it('respeta el plazo mientras no vence', function () {
    expect($this->politica->debeDevolverAlAgente(($this->handoff)('abierto', 59)))->toBeFalse();
});

it('nunca interrumpe a un asesor que ya tomó la conversación', function () {
    expect($this->politica->debeDevolverAlAgente(($this->handoff)('tomado', 600)))->toBeFalse();
});

it('ignora los handoffs ya cerrados', function () {
    expect($this->politica->debeDevolverAlAgente(($this->handoff)('cerrado', 600)))->toBeFalse();
});

it('no devuelve nada cuando falta opened_at', function () {
    $handoff = new Handoff;
    $handoff->state = 'abierto';

    expect($this->politica->debeDevolverAlAgente($handoff))->toBeFalse();
});
