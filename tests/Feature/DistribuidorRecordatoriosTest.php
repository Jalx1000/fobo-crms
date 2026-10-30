<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Distribuidor\Services\Recordatorios;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Services\TemplateCatalog;

/**
 * El recordatorio de recompra.
 *
 * El ciclo que estas pruebas cuidan:
 *
 *   entrega → (cadencia del cliente) → recordatorio
 *       ├─ "sí"      → el agente toma el pedido; el ciclo lo reinicia la entrega
 *       ├─ "no"      → +2 días, hasta el tope; después se corta
 *       ├─ "baja"    → no se le manda más, hasta que lo vuelva a pedir
 *       └─ silencio  → cuenta como "no"
 *
 * Lo más delicado no es que mande, es que DEJE de mandar: insistirle a alguien que
 * pidió que no termina en reportes de spam, y los reportes bajan la calidad del número
 * en Meta hasta que restringen la línea por la que entra todo el negocio.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();

    config([
        'distribuidor.recordatorios.enabled'          => true,
        'distribuidor.recordatorios.template'         => 'recordatorio_pedido',
        'distribuidor.recordatorios.language'         => 'es',
        // Estos casos prueban el cálculo por promedio: se apaga el plazo fijo (que en
        // producción es 4 días). El plazo fijo tiene su propio caso más abajo.
        'distribuidor.recordatorios.cadencia_fija'    => 0,
        'distribuidor.recordatorios.cadencia_defecto' => 7,
        'distribuidor.recordatorios.cadencia_minima'  => 3,
        'distribuidor.recordatorios.cadencia_maxima'  => 60,
        'distribuidor.recordatorios.reintento_dias'   => 2,
        'distribuidor.recordatorios.intentos_maximos' => 3,
    ]);
});

/** Una persona con teléfono y un lead, que es lo mínimo para tener historial. */
function clienteParaRecordatorio(?string $telefono = null): array
{
    $personId = (int) DB::table('persons')->insertGetId([
        'name'            => 'Cliente recordatorio '.uniqid(),
        'contact_numbers' => json_encode([[
            'value' => $telefono ?: '+54938840'.random_int(10000, 99999),
            'label' => 'work',
        ]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pipelineId = (int) DB::table('lead_pipelines')->orderBy('id')->value('id');

    // Entregado: el recordatorio es para clientes que ya recibieron su pedido. Con el
    // pedido en curso no se les manda nada (ver "con un pedido en curso…").
    $stageId = (int) (DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->where('code', config('distribuidor.etapas.entregado'))
        ->value('id')
        ?: DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)->orderBy('sort_order')->value('id'));

    $leadId = (int) DB::table('leads')->insertGetId([
        'title'                  => 'Pedido recordatorio '.uniqid(),
        'person_id'              => $personId,
        'lead_pipeline_id'       => $pipelineId,
        'lead_pipeline_stage_id' => $stageId,
        'lead_value'             => 1000,
        'user_id'                => 1,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    return ['person_id' => $personId, 'lead_id' => $leadId, 'pipeline_id' => $pipelineId];
}

/** Una entrega registrada en una fecha concreta. */
function entregaEn(int $leadId, $cuando): void
{
    DB::table('distribuidor_entregas')->insert([
        'lead_id'      => $leadId,
        'user_id'      => null,
        'entregado_at' => $cuando,
        'created_at'   => $cuando,
        'updated_at'   => $cuando,
    ]);
}

/** Otro pedido del mismo cliente, para poder fabricar varias entregas. */
function otroPedidoDe(array $cliente): int
{
    $stageId = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $cliente['pipeline_id'])->orderBy('sort_order')->value('id');

    return (int) DB::table('leads')->insertGetId([
        'title'                  => 'Otro pedido '.uniqid(),
        'person_id'              => $cliente['person_id'],
        'lead_pipeline_id'       => $cliente['pipeline_id'],
        'lead_pipeline_stage_id' => $stageId,
        'lead_value'             => 500,
        'user_id'                => 1,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
}

/** El catálogo con la plantilla del recordatorio aprobada. */
function conPlantillaDeRecordatorio(?array $header = null): void
{
    app()->instance(TemplateCatalog::class, new class($header) extends TemplateCatalog
    {
        public function __construct(protected ?array $head) {}

        public function find(string $name, string $language): ?array
        {
            return $name === 'recordatorio_pedido'
                ? [
                    'name'      => $name,
                    'language'  => $language,
                    'body'      => 'Hola {{1}}, ¿querés agendar tu próximo pedido?',
                    'variables' => 1,
                    'header'    => $this->head,
                ]
                : null;
        }

        public function render(string $body, array $variables): string
        {
            return str_replace('{{1}}', $variables[0] ?? '', $body);
        }
    });
}

// ───────────────────── la cadencia: cada cuánto compra ─────────────────────

it('sin historial usa la cadencia por defecto', function () {
    $c = clienteParaRecordatorio();

    // Con una sola entrega no hay ningún intervalo que promediar. No se inventa nada.
    expect(app(Recordatorios::class)->cadenciaDe($c['person_id']))->toBe(7);
});

it('calcula la cadencia del promedio entre sus entregas', function () {
    $c = clienteParaRecordatorio();

    // Tres entregas cada 10 días: el promedio es 10.
    entregaEn($c['lead_id'], now()->subDays(30));
    entregaEn(otroPedidoDe($c), now()->subDays(20));
    entregaEn(otroPedidoDe($c), now()->subDays(10));

    expect(app(Recordatorios::class)->cadenciaDe($c['person_id']))->toBe(10);
});

it('dos entregas del mismo dia no desploman la cadencia', function () {
    $c = clienteParaRecordatorio();

    /*
     * 🔴 Un pedido partido en dos entregas del mismo día no es un ciclo de compra de
     * 0 días. Con ese intervalo adentro el promedio se desploma y el recordatorio
     * sale al otro día de haberle entregado.
     */
    entregaEn($c['lead_id'], now()->subDays(20));
    entregaEn(otroPedidoDe($c), now()->subDays(10));
    entregaEn(otroPedidoDe($c), now()->subDays(10)->addHours(3));

    expect(app(Recordatorios::class)->cadenciaDe($c['person_id']))->toBe(10);
});

it('la cadencia no baja del minimo ni sube del maximo', function () {
    $corto = clienteParaRecordatorio();
    entregaEn($corto['lead_id'], now()->subDays(2));
    entregaEn(otroPedidoDe($corto), now()->subDay());

    $largo = clienteParaRecordatorio();
    entregaEn($largo['lead_id'], now()->subDays(400));
    entregaEn(otroPedidoDe($largo), now()->subDays(10));

    $s = app(Recordatorios::class);

    // Un promedio de 1 día sería spam; uno de 195 no describe ningún hábito.
    expect($s->cadenciaDe($corto['person_id']))->toBe(3)
        ->and($s->cadenciaDe($largo['person_id']))->toBe(60);
});

// ───────────────────── programar tras la entrega ─────────────────────

it('la entrega programa el proximo recordatorio segun su ritmo', function () {
    $c = clienteParaRecordatorio();

    entregaEn($c['lead_id'], now()->subDays(20));
    entregaEn(otroPedidoDe($c), now()->subDays(10));

    $estado = app(Recordatorios::class)->programarTrasEntrega($c['person_id'], now());

    expect($estado->cadencia_dias)->toBe(10)
        ->and($estado->proximo_at->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($estado->intentos)->toBe(0);
});

it('una compra nueva reinicia los intentos del ciclo', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));
    $s->registrarEnvio($c['person_id']);
    $s->registrarRespuesta($c['person_id'], 'no');

    expect($s->de($c['person_id'])->intentos)->toBe(1);

    // La insistencia se mide por ciclo, y una compra cierra el ciclo anterior.
    $s->programarTrasEntrega($c['person_id'], now());

    expect($s->de($c['person_id'])->intentos)->toBe(0)
        ->and($s->de($c['person_id'])->ultima_respuesta)->toBeNull();
});

// ───────────────────── el ciclo de respuestas ─────────────────────

it('el silencio cuenta como no y no se reenvia en cada corrida', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));
    $s->registrarEnvio($c['person_id']);

    /*
     * 🔴 El próximo intento se programa EN EL MISMO ACTO que el envío, antes de saber
     * qué va a contestar. Sin esto, un cliente que no responde queda con proximo_at
     * vencido y el comando se lo manda otra vez en CADA corrida: en vez de insistir
     * tres veces, insiste cada hora.
     */
    $estado = $s->de($c['person_id']);

    expect($estado->proximo_at->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($s->vencidos()->pluck('person_id')->all())->not->toContain($c['person_id']);
});

it('decir que si no programa nada: lo reinicia la entrega', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));
    $s->registrarEnvio($c['person_id']);

    $estado = $s->registrarRespuesta($c['person_id'], 'si');

    /*
     * Entre que dice que sí y que le llega el agua pueden pasar días. Programar el
     * próximo desde acá le mandaría el recordatorio siguiente antes de recibir éste.
     */
    expect($estado->proximo_at)->toBeNull()
        ->and($estado->intentos)->toBe(0)
        ->and($estado->ultima_respuesta)->toBe('si');
});

it('se corta al agotar el tope de intentos', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));

    // Tres intentos con un "no" entre medio, que es el camino real.
    for ($i = 0; $i < 3; $i++) {
        $s->registrarEnvio($c['person_id']);
        $s->registrarRespuesta($c['person_id'], 'no');
    }

    $estado = $s->de($c['person_id']);

    /*
     * 🔴 El tope no es una preferencia de diseño: insistirle cada 2 días a alguien que
     * ya dijo que no termina en reportes de spam, y los reportes bajan la calidad del
     * número en Meta hasta que restringen la línea.
     */
    expect($estado->intentos)->toBe(3)
        ->and($estado->proximo_at)->toBeNull()
        ->and($s->vencidos()->pluck('person_id')->all())->not->toContain($c['person_id']);
});

it('el tope tambien se respeta si la fecha quedo vencida a mano', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(30));

    /*
     * Una fila con los intentos agotados PERO con fecha vencida: pasa si alguien
     * corrige la tabla a mano, si se baja el tope en la config con ciclos en curso, o
     * si una corrida a medias dejó el estado mezclado.
     *
     * El tope se chequea en la consulta y no sólo al escribir, porque es la única
     * defensa que sigue en pie cuando el estado llega mal de otro lado.
     */
    DB::table('distribuidor_recordatorios')
        ->where('person_id', $c['person_id'])
        ->update(['intentos' => 3, 'proximo_at' => now()->subDay()]);

    expect($s->vencidos()->pluck('person_id')->all())->not->toContain($c['person_id']);
});

it('la baja del cliente corta todo y sobrevive a una compra nueva', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));
    $s->registrarEnvio($c['person_id']);
    $s->registrarRespuesta($c['person_id'], 'baja');

    expect($s->de($c['person_id'])->baja_at)->not->toBeNull()
        ->and($s->de($c['person_id'])->proximo_at)->toBeNull();

    /*
     * 🔴 Que haya vuelto a comprar NO significa que haya vuelto a querer los mensajes.
     * Si la entrega levantara la baja, bastaría con que el cliente siguiera siendo
     * cliente para volver a escribirle: justo lo que pidió que no pasara.
     */
    $s->programarTrasEntrega($c['person_id'], now());

    expect($s->de($c['person_id'])->proximo_at)->toBeNull()
        ->and($s->de($c['person_id'])->baja_at)->not->toBeNull();
});

it('la baja se levanta solo si el cliente la pide', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->registrarRespuesta($c['person_id'], 'baja');

    // El interruptor del equipo NO alcanza: encenderlo deja la baja en pie.
    $s->activar($c['person_id'], true);

    expect($s->de($c['person_id'])->puedeRecibir())->toBeFalse();

    // Reactivar es explícito y es lo que dispara el agente cuando el cliente lo pide.
    $s->reactivar($c['person_id']);

    expect($s->de($c['person_id'])->puedeRecibir())->toBeTrue()
        ->and($s->de($c['person_id'])->baja_at)->toBeNull();
});

it('el interruptor por cliente lo saca de la lista', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(30));

    expect($s->vencidos()->pluck('person_id')->all())->toContain($c['person_id']);

    $s->activar($c['person_id'], false);

    expect($s->vencidos()->pluck('person_id')->all())->not->toContain($c['person_id']);
});

it('apagado el interruptor general no programa ni lista nada', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(30));

    config(['distribuidor.recordatorios.enabled' => false]);

    expect($s->habilitado())->toBeFalse()
        ->and($s->vencidos())->toBeEmpty()
        ->and($s->programarTrasEntrega($c['person_id'], now()))->toBeNull();
});

// ───────────────────── el envío ─────────────────────

it('manda la plantilla y cuenta el intento', function () {
    conPlantillaDeRecordatorio();

    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);
    $s->programarTrasEntrega($c['person_id'], now()->subDays(30));

    $ok = app(\Webkul\Distribuidor\Services\RecordatorioDeRecompra::class)->enviarA($c['person_id']);

    expect($ok)->toBeTrue();

    Queue::assertPushed(SendMessage::class);

    $mensaje = DB::table('whatsapp_messages')->orderByDesc('id')->first();

    expect($mensaje->type)->toBe('template')
        ->and($mensaje->body)->toContain('¿querés agendar tu próximo pedido?')
        ->and($s->de($c['person_id'])->intentos)->toBe(1);
});

it('con una plantilla de video manda el video de Sensia, no el logo', function () {
    conPlantillaDeRecordatorio(['format' => 'VIDEO', 'media' => true, 'variables' => 0]);
    config(['distribuidor.recordatorios.media' => 'https://ejemplo.test/whatsapp/bienvenida-sensia.mp4']);

    $c = clienteParaRecordatorio();
    app(Recordatorios::class)->programarTrasEntrega($c['person_id'], now()->subDays(30));

    expect(app(\Webkul\Distribuidor\Services\RecordatorioDeRecompra::class)->enviarA($c['person_id']))->toBeTrue();

    $payload = json_decode(DB::table('whatsapp_messages')->orderByDesc('id')->value('payload'), true);

    expect($payload['header'])->toBe([
        'format' => 'VIDEO',
        'link'   => 'https://ejemplo.test/whatsapp/bienvenida-sensia.mp4',
    ]);
});

it('el recordatorio no le abre un pedido vacío al cliente', function () {
    conPlantillaDeRecordatorio();

    $c = clienteParaRecordatorio();
    app(Recordatorios::class)->programarTrasEntrega($c['person_id'], now()->subDays(30));

    $antes = DB::table('leads')->where('person_id', $c['person_id'])->count();

    expect(app(\Webkul\Distribuidor\Services\RecordatorioDeRecompra::class)->enviarA($c['person_id']))->toBeTrue()
        ->and(DB::table('leads')->where('person_id', $c['person_id'])->count())->toBe($antes);
});

it('un pedido vacío que abrió el CRM no traba el recordatorio', function () {
    conPlantillaDeRecordatorio();

    $c = clienteParaRecordatorio();
    app(Recordatorios::class)->programarTrasEntrega($c['person_id'], now()->subDays(30));

    $leadId = otroPedidoDe($c);
    DB::table('leads')->where('id', $leadId)->update([
        'title'      => \Webkul\Whatsapp\Services\LeadOpener::TITLE_PREFIX.'Cliente',
        'lead_value' => 0,
        'status'     => 1,
    ]);

    expect(app(\Webkul\Distribuidor\Services\RecordatorioDeRecompra::class)->enviarA($c['person_id']))->toBeTrue();
});

it('con un pedido en curso no se le manda el recordatorio', function () {
    conPlantillaDeRecordatorio();

    $c = clienteParaRecordatorio();
    app(Recordatorios::class)->programarTrasEntrega($c['person_id'], now()->subDays(30));

    // Ya volvió a pedir: tiene otro pedido sin entregar.
    $abierta = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $c['pipeline_id'])->orderBy('sort_order')->value('id');
    DB::table('leads')->where('id', otroPedidoDe($c))->update(['lead_pipeline_stage_id' => $abierta]);

    expect(app(\Webkul\Distribuidor\Services\RecordatorioDeRecompra::class)->enviarA($c['person_id']))->toBeFalse()
        ->and(app(Recordatorios::class)->de($c['person_id'])->intentos)->toBe(0);
});

// ───────────────────── el plazo fijo de Sensia: 4 días ─────────────────────

it('con plazo fijo, el recordatorio sale 4 días después de la entrega', function () {
    config(['distribuidor.recordatorios.cadencia_fija' => 4]);

    $c = clienteParaRecordatorio();
    $entrega = now()->subDay()->startOfMinute();

    $estado = app(Recordatorios::class)->programarTrasEntrega($c['person_id'], $entrega);

    expect($estado->proximo_at->toDateTimeString())->toBe($entrega->copy()->addDays(4)->toDateTimeString());
});

it('marcar el pedido como entregado desde el CRM programa el recordatorio', function () {
    config(['distribuidor.recordatorios.cadencia_fija' => 4]);

    $c = clienteParaRecordatorio();
    $lead = \Webkul\Lead\Models\Lead::find($c['lead_id']);

    // Es el evento por el que pasa cualquier edición del pedido (kanban, ficha, API).
    event('lead.city.synced', $lead);

    expect(app(Recordatorios::class)->de($c['person_id'])->proximo_at)->not->toBeNull();
});

it('volver a guardar el mismo pedido entregado no reinicia los intentos', function () {
    config(['distribuidor.recordatorios.cadencia_fija' => 4]);

    $c = clienteParaRecordatorio();
    $lead = \Webkul\Lead\Models\Lead::find($c['lead_id']);
    $s = app(Recordatorios::class);

    event('lead.city.synced', $lead);
    $s->de($c['person_id'])->update(['intentos' => 2]);

    event('lead.city.synced', $lead);   // otra edición del mismo pedido

    expect($s->de($c['person_id'])->intentos)->toBe(2);
});

it('el comando programa a los clientes que ya tenían su pedido entregado', function () {
    config(['distribuidor.recordatorios.cadencia_fija' => 4]);

    $c = clienteParaRecordatorio();

    test()->artisan('distribuidor:programar-recompra')->assertSuccessful();
    expect(app(Recordatorios::class)->de($c['person_id'])->proximo_at)->toBeNull();   // modo prueba

    test()->artisan('distribuidor:programar-recompra', ['--aplicar' => true])->assertSuccessful();
    expect(app(Recordatorios::class)->de($c['person_id'])->proximo_at)->not->toBeNull();
});

it('sin plantilla aprobada no gasta un intento', function () {
    /*
     * Un catálogo SIN la plantilla. Antes este caso usaba el catálogo real de Meta,
     * que no tenía 'recordatorio_pedido'; desde que se creó y aprobó, el test dependía
     * del estado de Meta y fallaba. El caso que prueba —qué pasa si la plantilla no
     * está— se arma acá.
     */
    app()->instance(TemplateCatalog::class, new class extends TemplateCatalog
    {
        public function __construct() {}

        public function find(string $name, string $language): ?array
        {
            return null;
        }
    });

    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);
    $s->programarTrasEntrega($c['person_id'], now()->subDays(30));

    $ok = app(\Webkul\Distribuidor\Services\RecordatorioDeRecompra::class)->enviarA($c['person_id']);

    /*
     * 🔴 Contar como intento un envío que nunca salió gastaría los tres del ciclo sin
     * que el cliente haya recibido una sola pregunta, y el cliente quedaría en
     * silencio por un problema de configuración nuestro.
     */
    expect($ok)->toBeFalse()
        ->and($s->de($c['person_id'])->intentos)->toBe(0);

    Queue::assertNotPushed(SendMessage::class);
});

it('un cliente sin telefono se apaga en vez de reintentarse para siempre', function () {
    conPlantillaDeRecordatorio();

    $personId = (int) DB::table('persons')->insertGetId([
        'name'            => 'Sin teléfono '.uniqid(),
        'contact_numbers' => json_encode([]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $s = app(Recordatorios::class);
    $s->programarTrasEntrega($personId, now()->subDays(30));

    $ok = app(\Webkul\Distribuidor\Services\RecordatorioDeRecompra::class)->enviarA($personId);

    // Sin esto queda vencido para siempre y el comando lo reintenta en cada corrida.
    expect($ok)->toBeFalse()
        ->and($s->de($personId)->activo)->toBeFalse();
});

it('el comando no manda nada en simulacro', function () {
    conPlantillaDeRecordatorio();

    $c = clienteParaRecordatorio();
    app(Recordatorios::class)->programarTrasEntrega($c['person_id'], now()->subDays(30));

    $this->artisan('distribuidor:recordatorios --dry-run')->assertSuccessful();

    Queue::assertNotPushed(SendMessage::class);
    expect(app(Recordatorios::class)->de($c['person_id'])->intentos)->toBe(0);
});

// ───────────────── lo que informa el agente ─────────────────

/** Una conversación de WhatsApp ligada a una persona. */
function conversacionDe(int $personId): int
{
    return (int) DB::table('whatsapp_conversations')->insertGetId([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '+54938840'.random_int(10000, 99999),
        'wa_name'    => 'Cliente recordatorio',
        'person_id'  => $personId,
        'status'     => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('el agente informa que el cliente dijo que si', function () {
    actingAsSanctumAuthenticatedAdmin();

    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);
    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));
    $s->registrarEnvio($c['person_id']);

    test()->postJson(
        'api/v1/whatsapp/conversations/'.conversacionDe($c['person_id']).'/recompra',
        ['respuesta' => 'si'],
    )
        ->assertOk()
        ->assertJsonPath('recompra.respuesta', 'si')
        ->assertJsonPath('recompra.proximo_at', null);

    expect($s->de($c['person_id'])->ultima_respuesta)->toBe('si');
});

it('el agente informa la baja y deja de mandarse', function () {
    actingAsSanctumAuthenticatedAdmin();

    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);
    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));

    test()->postJson(
        'api/v1/whatsapp/conversations/'.conversacionDe($c['person_id']).'/recompra',
        ['respuesta' => 'baja'],
    )
        ->assertOk()
        ->assertJsonPath('recompra.dado_de_baja', true);

    /*
     * La baja la informa el AGENTE y no la deduce el CRM: distinguir "ahora no" de
     * "no me escribas más" es interpretar lenguaje, y una baja mal detectada significa
     * seguir escribiéndole a alguien que pidió que no.
     */
    expect($s->de($c['person_id'])->puedeRecibir())->toBeFalse()
        ->and($s->vencidos()->pluck('person_id')->all())->not->toContain($c['person_id']);
});

it('solo acepta las tres respuestas previstas', function () {
    actingAsSanctumAuthenticatedAdmin();

    $c = clienteParaRecordatorio();

    // Un campo libre acá vuelve imposible saber después por qué alguien dejó de
    // recibir recordatorios.
    test()->postJson(
        'api/v1/whatsapp/conversations/'.conversacionDe($c['person_id']).'/recompra',
        ['respuesta' => 'tal vez'],
    )->assertStatus(422);
});

it('el agente puede reactivar a quien lo vuelve a pedir', function () {
    actingAsSanctumAuthenticatedAdmin();

    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);
    $s->registrarRespuesta($c['person_id'], 'baja');

    $conv = conversacionDe($c['person_id']);

    test()->postJson("api/v1/whatsapp/conversations/{$conv}/recompra/reactivar")
        ->assertOk()
        ->assertJsonPath('recompra.dado_de_baja', false);

    expect($s->de($c['person_id'])->puedeRecibir())->toBeTrue();
});

it('el payload del agente dice que no hay que volver a pedir el domicilio', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    app(Webkul\Distribuidor\Services\Ubicacion::class)->guardarDelMapa(
        $c['person_id'], -24.1858, -65.2995, null, ['direccion' => 'Belgrano 100'],
    );

    $s->programarTrasEntrega($c['person_id'], now()->subDays(10));
    $s->registrarEnvio($c['person_id']);

    $conv = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::find(conversacionDe($c['person_id']));

    $recompra = app(Webkul\Whatsapp\Services\AgentPayload::class)->recompra($conv);

    /*
     * 🔴 Esto es lo que resuelve el pedido del negocio: "que tome el pedido sin volver
     * a preguntar la ubicación, porque ya la tenemos". El agente no puede deducirlo
     * —desde su lado todas las conversaciones se parecen— así que se lo decimos, y le
     * mandamos el domicilio para que lo CONFIRME en vez de pedirlo de nuevo.
     */
    expect($recompra)->not->toBeNull()
        ->and($recompra['datos_completos'])->toBeTrue()
        ->and($recompra['domicilio']['direccion'])->toBe('Belgrano 100')
        ->and($recompra['esperando_respuesta'])->toBeTrue();
});

it('sin recordatorio pendiente el payload no trae el bloque', function () {
    $c = clienteParaRecordatorio();

    $conv = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::find(conversacionDe($c['person_id']));

    // Una conversación cualquiera no tiene nada que ver con esto: el bloque sobraría
    // y el agente se pondría a hablar de un recordatorio que nadie mandó.
    expect(app(Webkul\Whatsapp\Services\AgentPayload::class)->recompra($conv))->toBeNull();
});

it('pasada la ventana el bloque desaparece', function () {
    $c = clienteParaRecordatorio();
    $s = app(Recordatorios::class);

    $s->programarTrasEntrega($c['person_id'], now()->subDays(30));
    $s->registrarEnvio($c['person_id']);

    // El envío fue hace una semana: lo que el cliente escriba hoy ya no es "la
    // respuesta al recordatorio" sino una conversación nueva.
    DB::table('distribuidor_recordatorios')
        ->where('person_id', $c['person_id'])
        ->update(['ultimo_envio_at' => now()->subDays(7)]);

    $conv = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::find(conversacionDe($c['person_id']));

    expect(app(Webkul\Whatsapp\Services\AgentPayload::class)->recompra($conv))->toBeNull();
});

// ───────────────── el contador del tablero ─────────────────

it('el tablero cuenta las plantillas que salieron, no las que fallaron', function () {
    $conv = conversacionDe(clienteParaRecordatorio()['person_id']);

    $fila = fn (string $tipo, string $estado, string $plantilla) => [
        'conversation_id' => $conv,
        'direction'       => 'outbound',
        'sender'          => 'sistema',
        'type'            => $tipo,
        'body'            => 'x',
        'status'          => $estado,
        'payload'         => json_encode(['template' => $plantilla]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ];

    /*
     * Nombres únicos por corrida: la base es la de PRODUCCIÓN y ya tiene plantillas
     * reales con los nombres de verdad. Afirmar sobre 'bienvenida_sensia' contaba
     * también las que salieron esta semana, y el test fallaba por datos ajenos.
     */
    $marca = substr(uniqid(), -8);
    $saludo = "saludo_{$marca}";
    $recordatorio = "recordatorio_{$marca}";

    DB::table('whatsapp_messages')->insert([
        $fila('template', 'sent', $saludo),
        $fila('template', 'delivered', $saludo),
        $fila('template', 'read', $recordatorio),
        // Meta cobra el mensaje entregado, no el intento.
        $fila('template', 'failed', $recordatorio),
        $fila('template', 'queued', $recordatorio),
        // Un mensaje de texto no es una plantilla y no se paga igual.
        $fila('text', 'sent', 'no-aplica'),
    ]);

    $stats = test()->actingAs(getDefaultAdmin(), 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'plantillas-enviadas']))
        ->assertOk()
        ->json('statistics');

    $porMotivo = collect($stats['por_motivo'])->pluck('total', 'plantilla');

    expect($porMotivo[$saludo])->toBe(2)
        ->and($porMotivo[$recordatorio])->toBe(1)
        // El texto no entra en el corte de plantillas.
        ->and($porMotivo->has('no-aplica'))->toBeFalse();
});

it('el tablero dice a quién se envió cada plantilla', function () {
    $c = clienteParaRecordatorio();
    $conv = conversacionDe($c['person_id']);
    $plantilla = 'aviso_'.substr(uniqid(), -8);

    DB::table('whatsapp_messages')->insert([
        'conversation_id' => $conv, 'direction' => 'outbound', 'sender' => 'sistema', 'type' => 'template',
        'body' => 'x', 'status' => 'delivered', 'payload' => json_encode(['template' => $plantilla]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $envio = collect(test()->actingAs(getDefaultAdmin(), 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'plantillas-enviadas']))
        ->assertOk()
        ->json('statistics.envios'))
        ->firstWhere('plantilla', $plantilla);

    expect($envio)->not->toBeNull()
        ->and($envio['cliente'])->toBe((string) DB::table('persons')->where('id', $c['person_id'])->value('name'))
        ->and($envio['estado'])->toBe('delivered');
});

it('un distribuidor no ve las plantillas enviadas', function () {
    $rol = \Webkul\User\Models\Role::where('name', 'Distribuidor')->value('id')
        ?? test()->markTestSkipped('No existe el rol Distribuidor.');

    $distribuidor = \Webkul\User\Models\User::create([
        'name' => 'Dist plantillas '.uniqid(), 'email' => 'dist-pl-'.uniqid().'@test.local',
        'password' => bcrypt('x'), 'role_id' => $rol, 'status' => 1, 'view_permission' => 'individual',
    ]);

    test()->actingAs($distribuidor, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'plantillas-enviadas']))
        ->assertForbidden();
});
