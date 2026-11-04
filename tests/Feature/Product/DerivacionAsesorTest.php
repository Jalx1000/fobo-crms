<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Product\Models\ProductoResponsable;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Services\Responsables;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Derivación: nadie fuera de la matriz «Atiende» queda como asesor asignado
 * (administradores, el usuario del agente), y sin ciudad -o sin nadie en la
 * ciudad- cae en el asesor por defecto (cotizacion.asesor_por_defecto).
 *
 * Contra la base del contenedor con transacciones. La base ya tiene asesores
 * reales: el asesor por defecto de los tests es uno de prueba y la ciudad de
 * prueba es Cobija, que ningún asesor real atiende.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Http::fake();
    Mail::fake();
    Queue::fake();

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'DA test '.uniqid(),
        'sku'         => 'TEST-DA-'.uniqid(),
        'price'       => 10,
        'quantity'    => 0,
    ]);

    $this->porDefecto = daUsuario('Por defecto', 'Ejecutivo Comercial');
    daAtiende($this->producto, 'Bolivia', $this->porDefecto);

    config(['cotizacion.asesor_por_defecto' => $this->porDefecto->email]);

    $this->admin = User::findOrFail(DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.name', 'Administrador')->where('users.status', 1)->value('users.id'));
});

function daUsuario(string $nombre, string $rol): User
{
    return User::findOrFail(DB::table('users')->insertGetId([
        'name'       => $nombre.' '.uniqid(),
        'email'      => 'da'.uniqid().'@test.local',
        'password'   => bcrypt('x'),
        'role_id'    => DB::table('roles')->where('name', $rol)->value('id') ?? DB::table('roles')->value('id'),
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

function daAtiende($producto, string $ciudad, User $user): void
{
    ProductoResponsable::create(['product_id' => $producto->id, 'ciudad' => $ciudad, 'user_id' => $user->id]);
}

function daConversacion(?int $personOwner = null, ?int $leadOwner = null): Conversation
{
    $person = DB::table('persons')->insertGetId([
        'name' => 'DA '.uniqid(), 'emails' => '[]', 'user_id' => $personOwner, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $lead = DB::table('leads')->insertGetId([
        'title'                  => 'DA lead', 'user_id' => $leadOwner, 'person_id' => $person, 'status' => 1,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->value('id'),
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    return Conversation::create([
        'gateway'    => 'cloud_api', 'wa_phone' => '+5917'.random_int(1000000, 9999999),
        'ai_enabled' => true, 'status' => 'open', 'person_id' => $person, 'lead_id' => $lead,
    ]);
}

it('un asesor válido es un usuario activo de la matriz; un administrador no', function () {
    $r = app(Responsables::class);

    expect($r->esAsesor($this->porDefecto->id))->toBeTrue()
        ->and($r->esAsesor($this->admin->id))->toBeFalse();
});

it('sin ciudad deriva al asesor por defecto', function () {
    expect(app(Responsables::class)->porCiudad(null)?->id)->toBe($this->porDefecto->id);
});

it('con ciudad deriva a quien la atiende, y si nadie la atiende al asesor por defecto', function () {
    // Sin producto conocido, la ciudad la toma quien atiende TODOS los
    // productos ahí (13/10: Fabio, solo Hules en Santa Cruz, recibía todo).
    $local = daUsuario('Local Cobija', 'Ejecutivo Comercial');

    $productos = DB::table('producto_responsables')->distinct()->pluck('product_id')->push($this->producto->id)->unique();

    foreach ($productos as $id) {
        daAtiende(Webkul\Product\Models\Product::find($id), 'Cobija', $local);
    }

    $r = app(Responsables::class);

    expect($r->porCiudad('Cobija')?->id)->toBe($local->id)
        ->and($r->porCiudad('Sucre')?->id)->toBe($this->porDefecto->id);
});

it('sin producto, quien atiende un solo producto en la ciudad no se lleva la ciudad entera', function () {
    $parcial = daUsuario('Parcial Cobija', 'Ejecutivo Comercial');
    daAtiende($this->producto, 'Cobija', $parcial);

    expect(app(Responsables::class)->porCiudad('Cobija')?->id)->toBe($this->porDefecto->id);
});

it('un lead creado sin usuario (webhook) no queda a nombre de un administrador', function () {
    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'DA sin usuario', 'status' => 1,
        'person'      => ['name' => 'DA persona '.uniqid(), 'emails' => [], 'entity_type' => 'persons'],
    ]);

    expect($lead->user_id)->toBe($this->porDefecto->id);
});

it('un lead creado por un administrador logueado tampoco queda a su nombre', function () {
    $this->actingAs($this->admin, 'user');

    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'DA admin', 'status' => 1,
        'person'      => ['name' => 'DA persona '.uniqid(), 'emails' => [], 'entity_type' => 'persons'],
    ]);

    expect($lead->user_id)->toBe($this->porDefecto->id);
});

it('un asesor logueado que crea un lead sí queda como dueño', function () {
    $asesor = daUsuario('Asesor', 'Ejecutivo Comercial');
    daAtiende($this->producto, 'Cobija', $asesor);
    $this->actingAs($asesor, 'user');

    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'DA asesor', 'status' => 1,
        'person'      => ['name' => 'DA persona '.uniqid(), 'emails' => [], 'entity_type' => 'persons'],
    ]);

    expect($lead->user_id)->toBe($asesor->id);
});

it('un contacto nuevo por WhatsApp nace con el asesor por defecto en el contacto y el lead', function () {
    $conversation = app(ConversationResolver::class)->resolve(
        new ContactIdentity(phone: '+5917'.random_int(1000000, 9999999), name: 'Cliente DA'),
        null,
        'cloud_api',
    );

    expect($conversation->person->user_id)->toBe($this->porDefecto->id)
        ->and($conversation->lead->user_id)->toBe($this->porDefecto->id);
});

it('el handoff de un lead a nombre de un administrador va al asesor por defecto, no al pozo', function () {
    $conversation = daConversacion($this->admin->id, $this->admin->id);

    expect(app(Handoff::class)->ownerFor($conversation))->toBe($this->porDefecto->id);
});

it('el handoff respeta al asesor dueño del lead', function () {
    $asesor = daUsuario('Dueño', 'Supervisores');
    daAtiende($this->producto, 'Cobija', $asesor);

    expect(app(Handoff::class)->ownerFor(daConversacion(null, $asesor->id)))->toBe($asesor->id);
});

it('dos contactos de Messenger sin teléfono del mismo asesor se registran los dos', function () {
    $resolver = app(ConversationResolver::class);

    $uno = $resolver->resolveOrCreatePerson(new ContactIdentity(providerId: 'PSID-DA-1', name: 'Uno'), 'messenger');
    $dos = $resolver->resolveOrCreatePerson(new ContactIdentity(providerId: 'PSID-DA-2', name: 'Dos'), 'messenger');

    expect($uno)->not->toBeNull()
        ->and($dos)->not->toBeNull()
        ->and($dos->id)->not->toBe($uno->id)
        ->and($dos->user_id)->toBe($this->porDefecto->id);
});
