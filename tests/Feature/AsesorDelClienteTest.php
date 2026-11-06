<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;

/**
 * Un guardado parcial de la ficha no le borra el asesor al cliente.
 *
 * El agente corregía el nombre por la API, el asesor quedaba en null y el siguiente
 * pedido de ese cliente —una recompra— se iba al responsable en vez de a su
 * distribuidor (cliente 13053, pedidos 10914 y 11088).
 *
 * La suite corre contra la base real: todo va en una transacción.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->asesorId = (int) DB::table('users')->where('status', 1)->value('id');

    $this->personId = (int) DB::table('persons')->insertGetId([
        'name'       => 'Cliente asesor '.uniqid(),
        'user_id'    => $this->asesorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('conserva el asesor si el guardado no lo trae', function () {
    app(PersonRepository::class)->update([
        'entity_type' => 'persons',
        'name'        => 'Nombre corregido',
    ], $this->personId);

    expect((int) DB::table('persons')->where('id', $this->personId)->value('user_id'))->toBe($this->asesorId);
});

it('el formulario todavía puede quitar el asesor a propósito', function () {
    app(PersonRepository::class)->update([
        'entity_type' => 'persons',
        'name'        => 'Sin asesor',
        'user_id'     => '',
    ], $this->personId);

    expect(DB::table('persons')->where('id', $this->personId)->value('user_id'))->toBeNull();
});
