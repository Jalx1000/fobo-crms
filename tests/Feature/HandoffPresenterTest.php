<?php

use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Handoff;
use Webkul\Whatsapp\Services\HandoffPresenter;

/*
| Sin tocar la base: el presenter solo da forma a un handoff ya cargado. Las
| relaciones se inyectan con setRelation() para que loadMissing() no consulte.
*/

beforeEach(function () {
    $this->presenter = new HandoffPresenter;

    $this->conAsesor = function (string $state, int $userId, string $nombre): Handoff {
        $asesor = new User;
        $asesor->id = $userId;
        $asesor->name = $nombre;

        $handoff = new Handoff;
        $handoff->state = $state;
        $handoff->user_id = $userId;
        $handoff->reason = 'Quiere coordinar una visita.';
        $handoff->referencia_codigo = 'C21-0001';
        $handoff->opened_at = now()->subHour();
        $handoff->setRelation('asesor', $asesor);

        return $handoff;
    };
});

it('devuelve el estado vacío con todas las claves cuando no hay derivación', function () {
    $payload = $this->presenter->paraInbox(null);

    // Todas las claves siempre presentes: el front asigna el objeto entero en
    // cada poll, y una faltante dejaría en pantalla el valor anterior.
    expect($payload)->toHaveKeys([
        'state', 'open', 'tomado', 'reason', 'referencia', 'asesor', 'is_mine', 'opened_at', 'taken_at',
    ]);

    expect($payload['state'])->toBe('ninguno')
        ->and($payload['open'])->toBeFalse()
        ->and($payload['asesor'])->toBeNull();
});

it('marca como abierta y sin tomar una derivación que nadie atendió', function () {
    $payload = $this->presenter->paraInbox(($this->conAsesor)('abierto', 7, 'Ana'));

    expect($payload['open'])->toBeTrue()
        ->and($payload['tomado'])->toBeFalse()
        ->and($payload['asesor']['name'])->toBe('Ana')
        ->and($payload['referencia'])->toBe('C21-0001');
});

it('marca como tomada la derivación que un asesor ya atiende', function () {
    $payload = $this->presenter->paraInbox(($this->conAsesor)('tomado', 7, 'Ana'));

    expect($payload['open'])->toBeTrue()
        ->and($payload['tomado'])->toBeTrue();
});

it('reconoce al asesor que está mirando su propia derivación', function () {
    $handoff = ($this->conAsesor)('tomado', 7, 'Ana');

    expect($this->presenter->paraInbox($handoff, 7)['is_mine'])->toBeTrue()
        ->and($this->presenter->paraInbox($handoff, 9)['is_mine'])->toBeFalse()
        ->and($this->presenter->paraInbox($handoff)['is_mine'])->toBeFalse();
});

it('no considera abierta una derivación cerrada', function () {
    $payload = $this->presenter->paraInbox(($this->conAsesor)('cerrado', 7, 'Ana'));

    expect($payload['open'])->toBeFalse();
});
