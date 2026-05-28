<?php

use Webkul\Admin\Services\Insurance\Drivers\OdontokingMembershipDriver;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;

function mapOdontokingMembershipResponse(array $row): array
{
    $driver = new OdontokingMembershipDriver(
        Mockery::mock(AttributeRepository::class),
        Mockery::mock(AttributeValueRepository::class),
    );

    $method = new ReflectionMethod($driver, 'mapResponse');
    $method->setAccessible(true);

    return $method->invoke($driver, [
        'success' => true,
        'data'    => [$row],
    ]);
}

it('marca vigente una membresia odontoking encontrada sin observaciones negativas', function () {
    $result = mapOdontokingMembershipResponse([
        'row_number'        => 536,
        'Paciente_Original' => 'CARLA FLORES APARICIO (SISTEMA 2023) 9835390 76066015',
        'Nombre'            => 'CARLA FLORES APARICIO',
        'CI'                => '9835390',
        'Telefono'          => '76066015',
        'Año_Sistema'       => '2023',
        'Num_Sistema'       => '',
        'Observaciones'     => '',
        'Seguro'            => 'Membresia Odontoking',
        'Seguro2'           => '',
    ]);

    expect($result['status'])->toBe('VIGENTE')
        ->and($result['badge'])->toBe('success')
        ->and($result['data']['CI'])->toBe('9835390');
});

it('marca vencida una membresia odontoking con observacion vencida', function () {
    $result = mapOdontokingMembershipResponse([
        'Nombre'        => 'AARON FLORES MICHEL',
        'CI'            => '72555336',
        'Telefono'      => '72555336',
        'Observaciones' => 'MEMBRESIA VENCIDA',
        'Seguro'        => 'Membresia Odontoking',
    ]);

    expect($result['status'])->toBe('VENCIDO')
        ->and($result['badge'])->toBe('danger');
});

it('marca mora solo cuando observaciones indican mora o pagos pendientes', function () {
    $result = mapOdontokingMembershipResponse([
        'Nombre'        => 'PACIENTE MORA',
        'CI'            => '1234567',
        'Observaciones' => 'PAGOS PENDIENTES',
        'Seguro'        => 'Membresia Odontoking',
    ]);

    expect($result['status'])->toBe('EN_MORA')
        ->and($result['badge'])->toBe('danger');
});

it('respeta vigencia por fecha MF HASTA EL cuando existe', function () {
    $result = mapOdontokingMembershipResponse([
        'Nombre'        => 'PACIENTE FECHA',
        'CI'            => '1234568',
        'Observaciones' => 'MF HASTA EL 31/12/2099',
        'Seguro'        => 'Membresia Odontoking',
    ]);

    expect($result['status'])->toBe('VIGENTE')
        ->and($result['data']['VIGENCIA HASTA'])->toBe('31/12/2099');
});
