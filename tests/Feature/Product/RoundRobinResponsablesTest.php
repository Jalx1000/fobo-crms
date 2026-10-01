<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Product\Models\ProductoResponsable;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Services\Responsables;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Round robin del equipo de ventas: la matriz producto × ciudad, por turnos.
 *
 * Lo que se protege: local primero, el turno avanza, un cliente no cambia de
 * asesor a cada pedido, y el handoff cae en la misma persona aunque su rol
 * sea «Supervisores».
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'RR test '.uniqid(),
        'sku'         => 'TEST-RR-'.uniqid(),
        'price'       => 10,
        'quantity'    => 0,
    ]);
});

function rrUser(string $nombre, ?string $rol = null): int
{
    $roleId = $rol
        ? (DB::table('roles')->where('name', $rol)->value('id') ?? DB::table('roles')->insertGetId([
            'name' => $rol, 'permission_type' => 'custom', 'created_at' => now(), 'updated_at' => now(),
        ]))
        : DB::table('roles')->whereNotIn('name', (array) config('whatsapp.handoff.exclude_roles', []))->value('id');

    return DB::table('users')->insertGetId([
        'name'       => $nombre,
        'email'      => 'rr'.uniqid().'@test.local',
        'password'   => bcrypt('x'),
        'role_id'    => $roleId,
        'status'     => 1,
        'telefono'   => '700-00000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function rrAtiende($producto, string $ciudad, int $userId): void
{
    ProductoResponsable::create(['product_id' => $producto->id, 'ciudad' => $ciudad, 'user_id' => $userId]);
}

function rrConversacion(?int $duenoPersona = null): Conversation
{
    $persona = DB::table('persons')->insertGetId([
        'name' => 'RR '.uniqid(), 'emails' => '[]', 'user_id' => $duenoPersona, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return Conversation::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'RR',
        'ai_enabled' => true, 'status' => 'open', 'person_id' => $persona,
    ]);
}

it('el local va primero y Bolivia solo cubre donde no hay nadie', function () {
    $fabio = rrUser('Fabio');
    $mariano = rrUser('Mariano');

    rrAtiende($this->producto, 'Santa Cruz', $fabio);
    rrAtiende($this->producto, 'Bolivia', $mariano);

    $s = app(Responsables::class);

    expect($s->candidatos($this->producto, 'Santa Cruz')->pluck('user_id')->all())->toBe([$fabio]);
    expect($s->candidatos($this->producto, 'santa  cruz')->pluck('user_id')->all())->toBe([$fabio]);
    expect($s->candidatos($this->producto, 'La Paz')->pluck('user_id')->all())->toBe([$mariano]);
    expect($s->candidatos($this->producto, 'Tarija')->pluck('user_id')->all())->toBe([$mariano]);
    expect($s->candidatos($this->producto, null)->pluck('user_id')->all())->toBe([$mariano]);
});

it('se turnan: el siguiente es el que hace más tiempo no recibe', function () {
    $a = rrUser('A');
    $b = rrUser('B');
    $c = rrUser('C');

    rrAtiende($this->producto, 'La Paz', $a);
    rrAtiende($this->producto, 'La Paz', $b);
    rrAtiende($this->producto, 'La Paz', $c);

    $s = app(Responsables::class);

    $turnos = [];

    for ($i = 0; $i < 4; $i++) {
        // `ultimo_asignado_at` tiene resolución de segundos: cada turno pasa
        // en un segundo distinto, como en la vida real.
        \Illuminate\Support\Carbon::setTestNow(now()->addSeconds($i + 1));
        $turnos[] = $s->siguiente($this->producto, 'La Paz')->id;
    }

    \Illuminate\Support\Carbon::setTestNow();

    expect($turnos)->toBe([$a, $b, $c, $a]);
});

it('un asesor con cobertura nacional que acaba de recibir pasa al final en todas las ciudades', function () {
    $mariano = rrUser('Mariano');
    $otro = rrUser('Otro');

    rrAtiende($this->producto, 'Bolivia', $mariano);
    rrAtiende($this->producto, 'Bolivia', $otro);

    $s = app(Responsables::class);

    expect($s->siguiente($this->producto, 'La Paz')->id)->toBe($mariano);
    \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(2));
    expect($s->siguiente($this->producto, 'Cochabamba')->id)->toBe($otro);
    \Illuminate\Support\Carbon::setTestNow();
});

it('un contacto que ya tiene asesor candidato se queda con él sin mover el turno', function () {
    $a = rrUser('A');
    $b = rrUser('B');

    rrAtiende($this->producto, 'Santa Cruz', $a);
    rrAtiende($this->producto, 'Santa Cruz', $b);

    $conv = rrConversacion($b);

    expect(app(Responsables::class)->asignar($conv, $this->producto, 'Santa Cruz')->id)->toBe($b);
    expect(DB::table('producto_responsables')->where('product_id', $this->producto->id)->whereNotNull('ultimo_asignado_at')->count())->toBe(0);
});

it('un contacto cuyo dueño no atiende ese producto pasa al que le toca', function () {
    $viejo = rrUser('Viejo');
    $nuevo = rrUser('Nuevo');

    rrAtiende($this->producto, 'Santa Cruz', $nuevo);

    $conv = rrConversacion($viejo);

    expect(app(Responsables::class)->asignar($conv, $this->producto, 'Santa Cruz')->id)->toBe($nuevo);
    expect(DB::table('persons')->where('id', $conv->person_id)->value('user_id'))->toBe($nuevo);
});

it('un asignado que no vende no se queda con la cotización', function () {
    /*
     * El caso del 15/09: las conversaciones viejas quedaron con
     * `assigned_user_id` del usuario administrador por handoffs de prueba, y
     * cada cotización nueva salía a su nombre en vez del vendedor del turno.
     */
    $admin = getDefaultAdmin();
    $vendedor = rrUser('Vendedor');

    rrAtiende($this->producto, 'Santa Cruz', $vendedor);

    $conv = rrConversacion();
    $conv->forceFill(['assigned_user_id' => $admin->id, 'handoff_state' => 'assigned'])->save();

    expect(app(Responsables::class)->asignar($conv, $this->producto, 'Santa Cruz')->id)->toBe($vendedor);
});

it('un vendedor que no atiende ESE producto tampoco se lo queda', function () {
    $tapas = app(ProductRepository::class)->create([
        'entity_type' => 'products', 'name' => 'RR tapas '.uniqid(),
        'sku' => 'TEST-RR2-'.uniqid(), 'price' => 1, 'quantity' => 0,
    ]);

    $deTapas = rrUser('De tapas');
    $deHules = rrUser('De hules');

    rrAtiende($tapas, 'Santa Cruz', $deTapas);
    rrAtiende($this->producto, 'Santa Cruz', $deHules);

    // Su cliente de tapas ahora pregunta por el otro producto.
    $conv = rrConversacion($deTapas);

    expect(app(Responsables::class)->asignar($conv, $this->producto, 'Santa Cruz')->id)->toBe($deHules);

    DB::table('producto_responsables')->where('product_id', $tapas->id)->delete();
    DB::table('products')->where('id', $tapas->id)->delete();
});

it('la cotización del agente queda con el asesor que le toca y lo devuelve', function () {
    actingAsSanctumAuthenticatedAdmin();

    $gabriela = rrUser('Gabriela');
    rrAtiende($this->producto, 'La Paz', $gabriela);

    $conv = rrConversacion();

    $r = test()->postJson("/api/v1/productos/conversations/{$conv->id}/cotizacion", [
        'sku' => $this->producto->sku, 'cantidad' => 3, 'ciudad' => 'La Paz',
    ])->assertStatus(201);

    expect($r->json('asesor.id'))->toBe($gabriela)
        ->and($r->json('asesor.telefono'))->toBe('700-00000')
        ->and($r->json('asesor.horario'))->toBe('de 08:00 a 19:00');

    expect(DB::table('quotes')->where('id', $r->json('quote_id'))->value('user_id'))->toBe($gabriela);
});

it('sin ciudad explícita toma la Zona del precio cuando es una ciudad', function () {
    actingAsSanctumAuthenticatedAdmin();

    $sc = rrUser('SC');
    $nacional = rrUser('Nacional');
    rrAtiende($this->producto, 'Santa Cruz', $sc);
    rrAtiende($this->producto, 'Bolivia', $nacional);

    $conv = rrConversacion();

    $r = test()->postJson("/api/v1/productos/conversations/{$conv->id}/cotizacion", [
        'sku' => $this->producto->sku, 'cantidad' => 1, 'criterios' => ['Zona' => 'Santa Cruz'],
    ])->assertStatus(201);

    expect($r->json('asesor.id'))->toBe($sc);

    // «Interior» no es una ciudad: cae en la cobertura nacional.
    $r = test()->postJson("/api/v1/productos/conversations/".rrConversacion()->id."/cotizacion", [
        'sku' => $this->producto->sku, 'cantidad' => 1, 'criterios' => ['Zona' => 'Interior'],
    ])->assertStatus(201);

    expect($r->json('asesor.id'))->toBe($nacional);
});

it('sin ciudad en el pedido usa la que tiene anotada el contacto', function () {
    $sc = rrUser('SC');
    $nacional = rrUser('Nacional');
    rrAtiende($this->producto, 'Santa Cruz', $sc);
    rrAtiende($this->producto, 'Bolivia', $nacional);

    $conv = rrConversacion();

    $attr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
        ->findWhere(['entity_type' => 'persons', 'code' => 'ciudad'])->first();

    app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
        'entity_type' => 'persons',
        'entity_id'   => $conv->person_id,
        'ciudad'      => $attr->options->firstWhere('name', 'Santa Cruz')->id,
    ], collect([$attr]));

    $conv->load('person');

    expect(app(Responsables::class)->asignar($conv, $this->producto, null)->id)->toBe($sc);
});

it('el agente puede preguntar quién atiende sin mover el turno', function () {
    actingAsSanctumAuthenticatedAdmin();

    $a = rrUser('A');
    $b = rrUser('B');
    rrAtiende($this->producto, 'Cochabamba', $a);
    rrAtiende($this->producto, 'Cochabamba', $b);

    $r = test()->getJson("/api/v1/productos/responsables?sku={$this->producto->sku}&ciudad=Cochabamba")->assertOk();

    expect($r->json('cobertura'))->toBe('local')
        ->and($r->json('siguiente.id'))->toBe($a)
        ->and(collect($r->json('responsables'))->pluck('id')->all())->toBe([$a, $b])
        ->and($r->json('horario.desde'))->toBe('08:00');

    // Dos consultas seguidas: mismo siguiente.
    expect(test()->getJson("/api/v1/productos/responsables?sku={$this->producto->sku}&ciudad=Cochabamba")->json('siguiente.id'))->toBe($a);

    expect(test()->getJson("/api/v1/productos/responsables?sku={$this->producto->sku}&ciudad=Tarija")->json('cobertura'))->toBeNull();
    test()->getJson('/api/v1/productos/responsables?sku=NO-EXISTE')->assertStatus(404);
});

it('el handoff con producto y ciudad cae en el asesor del turno aunque sea Supervisor', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $bulmaro = rrUser('Bulmaro', 'Supervisores');
    rrAtiende($this->producto, 'Bolivia', $bulmaro);

    $conv = rrConversacion();

    $r = test()->postJson("/api/v1/whatsapp/conversations/{$conv->id}/handoff", [
        'reason' => 'Quiere hablar con alguien', 'sku' => $this->producto->sku, 'ciudad' => 'La Paz',
    ])->assertOk();

    // Para el agente `assigned_user` es el id pelado, con el nombre aparte.
    expect($r->json('handoff.assigned_user'))->toBe($bulmaro)
        ->and($r->json('handoff.assigned_user_name'))->toBe('Bulmaro');

    // Y un handoff posterior SIN producto respeta al dueño que dejó el round
    // robin, aunque su rol esté excluido del enrutamiento.
    $conv2 = rrConversacion($bulmaro);

    $r = test()->postJson("/api/v1/whatsapp/conversations/{$conv2->id}/handoff")->assertOk();

    // Para el agente `assigned_user` es el id pelado, con el nombre aparte.
    expect($r->json('handoff.assigned_user'))->toBe($bulmaro)
        ->and($r->json('handoff.assigned_user_name'))->toBe('Bulmaro');
});

it('lo que atiende cada persona se edita desde su ficha de usuario', function () {
    test()->actingAs(getDefaultAdmin());

    $fabio = rrUser('Fabio');

    rrAtiende($this->producto, 'La Paz', $fabio);   // se va a desmarcar

    // La ficha devuelve la matriz con una clave por producto.
    $r = test()->getJson(route('admin.settings.users.edit', $fabio))->assertOk();

    expect($r->json("data.atiende.{$this->producto->id}"))->toBe(['La Paz']);

    test()->putJson(route('admin.settings.users.update', $fabio), [
        'name'            => 'Fabio',
        'email'           => DB::table('users')->where('id', $fabio)->value('email'),
        'role_id'         => DB::table('users')->where('id', $fabio)->value('role_id'),
        'view_permission' => 'global',
        'status'          => 1,
        'telefono'        => '787-40409',
        'horario'         => 'de 08:00 a 19:00',
        'atiende'         => ['', $this->producto->id => ['Santa Cruz', 'Bolivia', 'Marte']],
    ])->assertOk();

    $filas = ProductoResponsable::where('product_id', $this->producto->id)->where('user_id', $fabio)->get();

    expect($filas->pluck('ciudad')->sort()->values()->all())->toBe(['Bolivia', 'Santa Cruz'])
        ->and(DB::table('users')->where('id', $fabio)->value('telefono'))->toBe('787-40409');

    // Sin el campo, no se toca (el editor inline del datagrid no lo manda).
    test()->putJson(route('admin.settings.users.update', $fabio), [
        'name'            => 'Fabio',
        'email'           => DB::table('users')->where('id', $fabio)->value('email'),
        'role_id'         => DB::table('users')->where('id', $fabio)->value('role_id'),
        'view_permission' => 'global',
    ])->assertOk();

    expect(ProductoResponsable::where('user_id', $fabio)->count())->toBe(2);
});

it('guardar la ficha no reinicia el turno de las casillas que siguen', function () {
    $a = rrUser('A');
    rrAtiende($this->producto, 'La Paz', $a);
    DB::table('producto_responsables')->where('user_id', $a)->update(['ultimo_asignado_at' => now()->subHour()]);

    app(Responsables::class)->guardarDe($a, [$this->producto->id => ['La Paz', 'Bolivia']]);

    expect(DB::table('producto_responsables')->where('user_id', $a)->where('ciudad', 'La Paz')->value('ultimo_asignado_at'))->not->toBeNull()
        ->and(DB::table('producto_responsables')->where('user_id', $a)->count())->toBe(2);
});

it('Equipo de ventas muestra la matriz y quién falta', function () {
    test()->actingAs(getDefaultAdmin());

    $fabio = rrUser('Fabio RR');
    rrAtiende($this->producto, 'Santa Cruz', $fabio);

    $html = test()->get(route('admin.settings.equipo_ventas.index'))->assertOk()->getContent();

    expect($html)->toContain($this->producto->name)
        ->and($html)->toContain('Fabio RR')
        ->and($html)->toContain('action=edit&amp;id='.$fabio)
        ->and($html)->toContain('Nadie');
});
