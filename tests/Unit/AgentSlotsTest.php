<?php

/*
| Turnos del agente IA (planning/16, RF-12 / D-14): 1 h primero, 30 min en lo
| que sobra, siempre empezando en :00 o :30.
*/

use Webkul\Admin\Services\AvailabilityResolverService;

function agentCut(array $ranges): array
{
    $slots = array_map(fn ($r) => ['start_time' => $r[0], 'end_time' => $r[1]], $ranges);

    return array_map(
        fn ($t) => $t['start_time'].'-'.$t['end_time'].'/'.$t['duration_minutes'],
        AvailabilityResolverService::agentSlots('2031-03-03', $slots, 'America/La_Paz'),
    );
}

it('corta en turnos de 1 h y deja uno de 30 en lo que sobra', function () {
    expect(agentCut([['09:00', '11:30']]))->toBe(['09:00-10:00/60', '10:00-11:00/60', '11:00-11:30/30']);
});

it('une bloques de 15 contiguos antes de cortar', function () {
    expect(agentCut([['09:00', '09:15'], ['09:15', '09:30'], ['09:30', '09:45'], ['09:45', '10:00']]))
        ->toBe(['09:00-10:00/60']);
});

it('arranca en la próxima :00 o :30', function () {
    expect(agentCut([['09:15', '11:00']]))->toBe(['09:30-10:30/60', '10:30-11:00/30'])
        ->and(agentCut([['09:45', '11:00']]))->toBe(['10:00-11:00/60']);
});

it('45 min desde las 10:00 da un turno de 30', function () {
    expect(agentCut([['10:00', '10:45']]))->toBe(['10:00-10:30/30']);
});

it('un hueco de 20 min no ofrece nada', function () {
    expect(agentCut([['10:00', '10:20']]))->toBe([])
        ->and(agentCut([['10:10', '10:40']]))->toBe([]);
});

it('huecos separados se cortan cada uno por su lado', function () {
    expect(agentCut([['09:00', '10:30'], ['14:00', '15:00']]))
        ->toBe(['09:00-10:00/60', '10:00-10:30/30', '14:00-15:00/60']);
});
