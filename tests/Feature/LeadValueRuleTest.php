<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Regla de negocio: lead_value refleja la SUMA de los productos del lead.
 * Solo un Administrador puede fijar un valor manual distinto (override),
 * marcado con lead_value_is_manual. Un no-admin nunca sobrescribe ese override.
 */
beforeEach(function () {
    $this->personId = DB::table('persons')->value('id');
    $this->productId = DB::table('products')->value('id');

    $this->admin = User::create([
        'name'     => 'Admin '.uniqid(),
        'email'    => 'admin'.uniqid().'@test.com',
        'password' => bcrypt('secret'),
        'role_id'  => 1, // Administrator
        'status'   => 1,
    ]);

    $this->staff = User::create([
        'name'     => 'Recepcion '.uniqid(),
        'email'    => 'staff'.uniqid().'@test.com',
        'password' => bcrypt('secret'),
        'role_id'  => 2, // Recepcionista
        'status'   => 1,
    ]);
});

function makeLeadData($personId, $productId, $leadValue, $price, $qty): array
{
    return [
        'title'       => 'Lead '.uniqid(),
        'entity_type' => 'leads',
        'lead_value'  => $leadValue,
        'person'      => ['id' => $personId],
        'products'    => [
            [
                'product_id' => $productId,
                'name'       => 'Servicio',
                'price'      => $price,
                'quantity'   => $qty,
            ],
        ],
    ];
}

it('un no-admin obtiene lead_value = suma de productos (ignora el valor enviado)', function () {
    $this->actingAs($this->staff, 'user');

    $lead = app(LeadRepository::class)->create(
        makeLeadData($this->personId, $this->productId, 999, 100, 2)
    );

    $lead->refresh();

    expect((float) $lead->lead_value)->toBe(200.0);
    expect($lead->lead_value_is_manual)->toBeFalse();
});

it('un Administrador puede fijar un valor manual distinto de la suma', function () {
    $this->actingAs($this->admin, 'user');

    $lead = app(LeadRepository::class)->create(
        makeLeadData($this->personId, $this->productId, 999, 100, 2)
    );

    $lead->refresh();

    expect((float) $lead->lead_value)->toBe(999.0);
    expect($lead->lead_value_is_manual)->toBeTrue();
});

it('un Administrador que envía el valor igual a la suma NO marca manual', function () {
    $this->actingAs($this->admin, 'user');

    $lead = app(LeadRepository::class)->create(
        makeLeadData($this->personId, $this->productId, 200, 100, 2)
    );

    $lead->refresh();

    expect((float) $lead->lead_value)->toBe(200.0);
    expect($lead->lead_value_is_manual)->toBeFalse();
});

it('un no-admin no puede borrar el override manual de un admin', function () {
    // Admin crea con override manual.
    $this->actingAs($this->admin, 'user');
    $lead = app(LeadRepository::class)->create(
        makeLeadData($this->personId, $this->productId, 999, 100, 2)
    );

    // Un recepcionista actualiza (cambio de título, sin tocar el valor).
    $this->actingAs($this->staff, 'user');
    app(LeadRepository::class)->update([
        'title'       => 'Editado por staff',
        'entity_type' => 'leads',
    ], $lead->id);

    $lead->refresh();

    expect((float) $lead->lead_value)->toBe(999.0);
    expect($lead->lead_value_is_manual)->toBeTrue();
});
