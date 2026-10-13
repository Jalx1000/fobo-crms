<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\VisibilidadLeads;
use Webkul\Core\Services\AlcanceCiudades;
use Webkul\Distribuidor\Services\Alcance;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * El alcance por ciudad (pipeline), compartido por el Punto y por WhatsApp.
 *
 * El agujero que cierra: la bandeja de WhatsApp tenía un filtro de ciudad, pero
 * lo elegía el asesor. No mandarlo —o mandar la ciudad de otro— devolvía la
 * bandeja entera, y pedir /whatsapp/thread?conversation_id=N con un id correlativo
 * daba el hilo completo de cualquier cliente de cualquier ciudad.
 *
 * La regla es una sola para los dos módulos: SIN filas ve todas, CON filas ve
 * sólo esas. Por eso casi todos estos tests cargan filas explícitamente — sin
 * filas no hay restricción que verificar.
 */
uses(DatabaseTransactions::class);

afterEach(fn () => DB::rollBack());

/** Las ciudades reales, salteando los pipelines que no son ciudades. */
function ciudadesDeAlcance(): array
{
    $excluidas = (array) config('whatsapp.handoff.exclude_cities', []);

    return DB::table('lead_pipelines')
        ->orderBy('id')
        ->get(['id', 'name'])
        ->reject(fn ($p) => in_array($p->name, $excluidas, true))
        ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name])
        ->values()
        ->all();
}

/**
 * Un usuario con rol a medida y los permisos que se le pasen.
 *
 * Rol propio y no uno real: si mañana a "Encargado" le cambian los permisos,
 * estos tests no empiezan a mentir.
 */
function usuarioDeAlcance(array $permisos = ['whatsapp', 'distribuidor']): User
{
    $roleId = (int) DB::table('roles')->insertGetId([
        'name'            => 'Rol alcance '.uniqid(),
        'description'     => 'Temporal, sólo para tests',
        'permission_type' => 'custom',
        'permissions'     => json_encode($permisos),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $userId = (int) DB::table('users')->insertGetId([
        'name'       => 'Test alcance '.uniqid(),
        'email'      => 'alcance-'.uniqid().'@test.local',
        'password'   => bcrypt('password'),
        'status'     => 1,
        'role_id'    => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::find($userId);
}

/** Le asigna ciudades al usuario. Sin esto, ve todas. */
function asignarCiudades(User $user, array $pipelineIds): void
{
    foreach ($pipelineIds as $id) {
        DB::table('user_ciudades')->insert([
            'user_id'          => $user->id,
            'lead_pipeline_id' => $id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}

/** Mete al usuario en un grupo de Krayin, creándolo si hace falta. */
function ponerEnGrupo(User $user, string $nombreGrupo): int
{
    $groupId = (int) (DB::table('groups')->where('name', $nombreGrupo)->value('id')
        ?: DB::table('groups')->insertGetId([
            'name'        => $nombreGrupo,
            'description' => 'Temporal, sólo para tests',
        ]));

    DB::table('user_groups')->insert(['user_id' => $user->id, 'group_id' => $groupId]);

    return $groupId;
}

/**
 * Una conversación en una ciudad.
 *
 * Usa handoff_city_id porque es el primer escalón de la cascada de
 * ConversationCity: deja el dato sin depender de que haya lead ni atributo.
 */
function convDeAlcance(?int $ciudadId): object
{
    return ConversationProxy::modelClass()::create([
        'gateway'         => config('whatsapp.gateway'),
        'wa_phone'        => '+5493'.random_int(10000000, 99999999),
        'wa_name'         => 'Alcance '.uniqid(),
        'handoff_city_id' => $ciudadId,
    ]);
}

// ─────────────────────────── el servicio compartido ───────────────────────────

it('sin filas asignadas el usuario ve todas las ciudades', function () {
    $user = usuarioDeAlcance();

    expect(app(AlcanceCiudades::class)->ciudadesDe($user->id))->toBeNull();
});

it('con filas asignadas ve sólo esas ciudades', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    $alcance = app(AlcanceCiudades::class);

    expect($alcance->ciudadesDe($user->id))->toBe([$ciudades[0]['id']])
        ->and($alcance->puedeVer($ciudades[0]['id'], $user->id))->toBeTrue()
        ->and($alcance->puedeVer($ciudades[1]['id'], $user->id))->toBeFalse();
});

it('sin usuario autenticado no ve ninguna ciudad', function () {
    // Array vacío y no null: un llamado sin sesión no hereda "todas".
    expect(app(AlcanceCiudades::class)->ciudadesDe(null))->toBe([]);
});

it('el selector de ciudades sólo ofrece las asignadas', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);
    $this->actingAs($user, 'user');

    $ids = array_column(app(AlcanceCiudades::class)->ciudades(), 'id');

    expect($ids)->toBe([$ciudades[0]['id']]);
});

it('la regla no tiene excepción para el administrador', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    // Una fila vale para todos. Un "salvo los admins" escondido haría que la
    // tabla mienta: alguien la carga y sigue viendo todo sin entender por qué.
    $admin = getDefaultAdmin();
    asignarCiudades($admin, [$ciudades[0]['id']]);

    expect(app(AlcanceCiudades::class)->puedeVer($ciudades[1]['id'], $admin->id))->toBeFalse();
});

// ──────────────────────── la bandeja de WhatsApp: lista ────────────────────────

it('el asesor restringido no ve en la lista las conversaciones de otra ciudad', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $mia = convDeAlcance($ciudades[0]['id']);
    $ajena = convDeAlcance($ciudades[1]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    $ids = collect($this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.conversations'))
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($mia->id)
        ->and($ids)->not->toContain($ajena->id);
});

it('pedir la ciudad de otro no evade el alcance', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $ajena = convDeAlcance($ciudades[1]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    // El filtro lo elige el asesor; el alcance no se puede elegir.
    $ids = collect($this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.conversations', ['city' => $ciudades[1]['id']]))
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->not->toContain($ajena->id);
});

it('las conversaciones sin ciudad las ve igual el asesor restringido', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    // Sin ciudad no las tiene nadie asignadas, así que son de todos: si el
    // alcance las escondiera, ningún asesor restringido las atendería.
    $huerfana = convDeAlcance(null);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    $ids = collect($this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.conversations'))
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($huerfana->id);
});

it('el asesor sin filas sigue viendo todas las ciudades', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $a = convDeAlcance($ciudades[0]['id']);
    $b = convDeAlcance($ciudades[1]['id']);

    $ids = collect($this->actingAs(usuarioDeAlcance(), 'user')
        ->getJson(route('admin.whatsapp.conversations'))
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($a->id)->and($ids)->toContain($b->id);
});

it('el conteo por ciudad no ofrece ciudades fuera del alcance', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    convDeAlcance($ciudades[1]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    $ofrecidas = collect($this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.cities'))
        ->assertOk()->json('cities'))->pluck('id')->all();

    expect($ofrecidas)->not->toContain($ciudades[1]['id']);
});

// ───────────────── la bandeja de WhatsApp: acceso por id directo ─────────────────

it('abrir por id el hilo de otra ciudad da 403', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $ajena = convDeAlcance($ciudades[1]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    $this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.thread', ['conversation_id' => $ajena->id]))
        ->assertStatus(403);
});

it('el hilo de su propia ciudad se abre normal', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    $mia = convDeAlcance($ciudades[0]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    $this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.thread', ['conversation_id' => $mia->id]))
        ->assertOk();
});

it('escribirle a una conversación de otra ciudad da 403', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $ajena = convDeAlcance($ciudades[1]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    // Leer no era el único problema: también se le escribía al cliente en
    // nombre de la empresa, y se le tomaba la derivación.
    $this->actingAs($user, 'user')
        ->postJson(route('admin.whatsapp.notes.store', ['id' => $ajena->id]), ['comment' => 'hola'])
        ->assertStatus(403);

    $this->actingAs($user, 'user')
        ->postJson(route('admin.whatsapp.handoff.claim', ['id' => $ajena->id]))
        ->assertStatus(403);
});

it('el id de una persona no se confunde con el de una conversación', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    $personId = (int) DB::table('persons')->insertGetId([
        'name'       => 'Persona alcance '.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    // persons/{id} comparte el nombre de parámetro con conversations/{id}: el
    // guard no va en ese grupo justamente para no leer una persona como
    // conversación y negar el acceso por un id que está bien.
    $this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.person', ['id' => $personId]))
        ->assertOk();
});

// ─────────────────────────────── el Punto ───────────────────────────────

it('el Punto usa el mismo alcance que WhatsApp', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);
    $this->actingAs($user, 'user');

    // Una sola tabla para los dos módulos: asignarle Jujuy al distribuidor no
    // puede dejar al asesor viendo todas las ciudades.
    expect(array_column(app(Alcance::class)->ciudades(), 'id'))->toBe([$ciudades[0]['id']])
        ->and(app(Alcance::class)->puedeVerCiudad($ciudades[1]['id']))->toBeFalse();
});

// ───────────────── la ciudad deducida del grupo de Krayin ─────────────────

it('la ciudad sale del grupo de Krayin, sin cargar nada a mano', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    // Los grupos ya existen y el negocio los mantiene. Exigir además que se llene
    // user_ciudades con lo mismo es la forma segura de que las dos se separen.
    $user = usuarioDeAlcance();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);
    ponerEnGrupo($user, $ciudades[0]['name']);

    expect(app(AlcanceCiudades::class)->ciudadesDe($user->id))->toBe([$ciudades[0]['id']]);
});

it('el puente grupo a ciudad va por nombre y no por id', function () {
    // 🔴 El grupo 1 es "San Salvador de Jujuy" y el pipeline 1 es "Palpalá". Si
    // alguien cambia el puente a comparar ids, este test es el que avisa: el
    // distribuidor termina con la ciudad equivocada y sin ningún error.
    $grupos = DB::table('groups')->pluck('name', 'id');
    $pipelines = DB::table('lead_pipelines')->pluck('name', 'id');

    $grupoId = collect($grupos)->search(
        fn ($nombre, $id) => isset($pipelines[$id]) && $pipelines[$id] !== $nombre
    );

    if ($grupoId === false) {
        $this->markTestSkipped('En estos datos los ids no se contradicen.');
    }

    $user = usuarioDeAlcance();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);
    DB::table('user_groups')->insert(['user_id' => $user->id, 'group_id' => $grupoId]);

    $esperado = (int) collect($pipelines)->search($grupos[$grupoId]);

    expect(app(AlcanceCiudades::class)->ciudadesDe($user->id))->toBe([$esperado])
        ->and($esperado)->not->toBe((int) $grupoId);
});

it('un grupo que no es una ciudad no restringe', function () {
    // Un grupo "Ventas" no habla de ciudades, así que no tiene nada que decir
    // sobre ciudades. Dejarlo en "no ve ninguna" encerraría al usuario sin que
    // nadie lo haya pedido.
    $user = usuarioDeAlcance();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);
    ponerEnGrupo($user, 'Grupo sin ciudad '.uniqid());

    expect(app(AlcanceCiudades::class)->ciudadesDe($user->id))->toBeNull();
});

it('quien ve todo el CRM sigue viendo todas las ciudades', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    // view_permission = global. No tiene sentido que la bandeja restrinja a quien
    // el ACL de Krayin no restringe, incluso si está en un grupo-ciudad.
    $user = usuarioDeAlcance();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'global']);
    ponerEnGrupo($user, $ciudades[0]['name']);

    expect(app(AlcanceCiudades::class)->ciudadesDe($user->id))->toBeNull();
});

it('las filas cargadas a mano ganan sobre el grupo', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    // user_ciudades es el override: alguien la cargó a propósito.
    $user = usuarioDeAlcance();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);
    ponerEnGrupo($user, $ciudades[0]['name']);
    asignarCiudades($user, [$ciudades[1]['id']]);

    expect(app(AlcanceCiudades::class)->ciudadesDe($user->id))->toBe([$ciudades[1]['id']]);
});

it('el distribuidor del grupo de una ciudad no ve los pedidos de las otras', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $user = usuarioDeAlcance();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);
    ponerEnGrupo($user, $ciudades[0]['name']);
    $this->actingAs($user, 'user');

    // El caso de verdad: el Punto le muestra sólo su ciudad.
    expect(app(Alcance::class)->puedeVerCiudad($ciudades[0]['id']))->toBeTrue()
        ->and(app(Alcance::class)->puedeVerCiudad($ciudades[1]['id']))->toBeFalse()
        ->and(array_column(app(Alcance::class)->ciudades(), 'id'))->toBe([$ciudades[0]['id']]);
});

// ───────────── los leads: por ciudad y no por dueño del lead ─────────────

/** Un lead de esa ciudad, cargado por otro usuario. */
function leadAjenoEn(int $pipelineId, int $duenoId): int
{
    // lead_pipeline_stages tiene lead_pipeline_id directo: no hay tabla pivote.
    $stageId = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->orderBy('sort_order')
        ->value('id');

    return (int) DB::table('leads')->insertGetId([
        'title'                  => 'Lead alcance '.uniqid(),
        'lead_pipeline_id'       => $pipelineId,
        'lead_pipeline_stage_id' => $stageId,
        'user_id'                => $duenoId,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
}

/** Un usuario con grupo-ciudad y view_permission = group. */
function usuarioDeCiudad(string $nombreCiudad, array $permisos = ['leads', 'leads.view', 'distribuidor', 'whatsapp']): User
{
    $user = usuarioDeAlcance($permisos);
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);
    ponerEnGrupo($user, $nombreCiudad);

    return $user->refresh();
}

it('ve los leads de su ciudad aunque los haya cargado otro', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $user = usuarioDeCiudad($ciudades[0]['name']);
    $this->actingAs($user, 'user');

    // El caso real: los pedidos los carga el encargado o el agente, no el
    // distribuidor que después los reparte. Por dueño veía 0.
    $propio = leadAjenoEn($ciudades[0]['id'], 1);
    $ajeno = leadAjenoEn($ciudades[1]['id'], 1);

    $query = DB::table('leads');
    VisibilidadLeads::aplicar($query);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($propio)
        ->and($ids)->not->toContain($ajeno);
});

it('la ciudad manda sobre el dueño sólo con view_permission de grupo', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    $user = usuarioDeCiudad($ciudades[0]['name']);

    expect(app(AlcanceCiudades::class)->ciudadMandaSobreDueno($user->id))->toBeTrue();
});

it('quien ve sólo lo suyo no gana acceso a la ciudad entera', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    // view_permission = individual significa "sólo lo tuyo". Darle la ciudad
    // entera le AMPLIARÍA el acceso, que es lo contrario de lo que pide el valor.
    $user = usuarioDeCiudad($ciudades[0]['name']);
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'individual']);
    $this->actingAs($user->refresh(), 'user');

    $deOtro = leadAjenoEn($ciudades[0]['id'], 1);

    expect(app(AlcanceCiudades::class)->ciudadMandaSobreDueno($user->id))->toBeFalse();

    $query = DB::table('leads');
    VisibilidadLeads::aplicar($query);

    expect($query->pluck('id')->all())->not->toContain($deOtro);
});

it('quien ve todo el CRM no queda acotado por ciudad en los leads', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $this->actingAs(getDefaultAdmin(), 'user');

    $a = leadAjenoEn($ciudades[0]['id'], 1);
    $b = leadAjenoEn($ciudades[1]['id'], 1);

    $query = DB::table('leads');
    VisibilidadLeads::aplicar($query);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($a)->and($ids)->toContain($b);
});

it('no se abre por URL el lead de otra ciudad', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $user = usuarioDeCiudad($ciudades[0]['name']);
    $ajeno = leadAjenoEn($ciudades[1]['id'], 1);

    // Acotar la grilla no alcanza si el id en la URL sigue funcionando.
    $this->actingAs($user, 'user')
        ->get(route('admin.leads.view', $ajeno))
        ->assertRedirect(route('admin.leads.index'));
});

it('sí se abre el lead de su propia ciudad cargado por otro', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    $user = usuarioDeCiudad($ciudades[0]['name']);
    $propio = leadAjenoEn($ciudades[0]['id'], 1);

    $this->actingAs($user, 'user')
        ->get(route('admin.leads.view', $propio))
        ->assertOk();
});

// ───────── el agujero: entrar por teléfono o por persona, sin id ─────────

it('no se lee por telefono la conversacion de otra ciudad', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $ajena = convDeAlcance($ciudades[1]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    /*
     * El agujero que esto cierra: el inbox embebido en la ficha del contacto, del
     * lead y en el Punto NO manda conversation_id —manda phone, person_id o
     * lead_id—, y el guard sólo miraba conversation_id. Alcanzaba con pedir el hilo
     * con el teléfono para leer la conversación de cualquier cliente.
     */
    $this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.thread', ['phone' => $ajena->wa_phone]))
        ->assertStatus(403);
});

it('no se lee por person_id la conversacion de otra ciudad', function () {
    $ciudades = ciudadesDeAlcance();

    if (count($ciudades) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades reales.');
    }

    $personId = (int) DB::table('persons')->insertGetId([
        'name'       => 'Cliente ajeno '.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ajena = convDeAlcance($ciudades[1]['id']);
    $ajena->update(['person_id' => $personId]);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    $this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.thread', ['person_id' => $personId]))
        ->assertStatus(403);
});

it('por telefono sí se lee la conversacion de su propia ciudad', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    $mia = convDeAlcance($ciudades[0]['id']);

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    // El guard no puede romper el camino legítimo: el inbox embebido entra así.
    $this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.thread', ['phone' => $mia->wa_phone]))
        ->assertOk();
});

it('un telefono sin conversacion todavia no se bloquea', function () {
    $ciudades = ciudadesDeAlcance();

    if (! $ciudades) {
        $this->markTestSkipped('Hace falta una ciudad real.');
    }

    $user = usuarioDeAlcance();
    asignarCiudades($user, [$ciudades[0]['id']]);

    // Sin conversación no hay nada que filtrar, y es el caso normal de escribirle
    // por primera vez a un cliente nuevo.
    $this->actingAs($user, 'user')
        ->getJson(route('admin.whatsapp.thread', ['phone' => '+5493889'.random_int(100000, 999999)]))
        ->assertOk();
});
