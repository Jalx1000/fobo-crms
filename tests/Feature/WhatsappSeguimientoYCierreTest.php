<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\SilenceTracker;

/*
 * Seguimiento a las 16 h y cierre a las 72 h sin respuesta del cliente.
 * Spec: tasks/spec-sin-respuesta-3-dias.md.
 *
 * Las plantillas de reapertura tuvieron 0 respuestas de 36 en 30 días: por eso
 * el recordatorio es un texto dentro de la ventana y las plantillas no cuentan.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    Queue::fake();
    DB::beginTransaction();

    config(['whatsapp.auto_close.since' => now()->subDays(10)->toDateTimeString()]);
});

afterEach(fn () => DB::rollBack());

function sycStage(string $pipeline, string $code): int
{
    $pipelineId = (int) DB::table('lead_pipelines')->where('name', $pipeline)->value('id');

    return (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->get(['id', 'code'])
        ->first(fn ($s) => ltrim($s->code, '-') === $code)
        ->id;
}

function sycLead(string $pipeline = 'Cochabamba', string $code = 'cliente-contactado', string $title = 'Pedido Club del Vino - JUAN CARLOS'): int
{
    return (int) DB::table('leads')->insertGetId([
        'title'                  => $title,
        'lead_value'             => 0,
        'status'                 => 1,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->where('name', $pipeline)->value('id'),
        'lead_pipeline_stage_id' => sycStage($pipeline, $code),
        'created_at'             => now()->subDays(5),
        'updated_at'             => now()->subDays(5),
    ]);
}

/** Conversación con su persona, como en producción (sin persona el resolver le abre otro pedido). */
function sycConv(int $leadId, array $attrs = []): Conversation
{
    $phone = '5917'.random_int(1000000, 9999999);

    $personId = (int) DB::table('persons')->insertGetId([
        'name'            => 'Persona seguimiento '.uniqid(),
        'contact_numbers' => json_encode([['label' => 'work', 'value' => $phone]]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    DB::table('leads')->where('id', $leadId)->update(['person_id' => $personId]);

    return ConversationProxy::modelClass()::create(array_merge([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '+'.$phone,
        'wa_name'    => 'Perfil',
        'status'     => 'open',
        'ai_enabled' => true,
        'person_id'  => $personId,
        'lead_id'    => $leadId,
    ], $attrs));
}

/** Mensaje con hora explícita; actualiza las marcas de la conversación como en producción. */
function sycMsg(Conversation $conv, string $direction, float $hoursAgo, array $attrs = []): void
{
    $at = now()->subMinutes((int) round($hoursAgo * 60));

    DB::table('whatsapp_messages')->insert(array_merge([
        'conversation_id' => $conv->id,
        'direction'       => $direction,
        'type'            => 'text',
        'body'            => $direction === 'inbound' ? 'hola' : 'respuesta',
        'status'          => $direction === 'inbound' ? 'delivered' : 'sent',
        'sender'          => $direction === 'inbound' ? 'contact' : 'ia',
        'created_at'      => $at,
        'updated_at'      => $at,
    ], $attrs));

    $conv->forceFill(array_filter([
        'last_message_at' => $at,
        'last_inbound_at' => $direction === 'inbound' ? $at : null,
    ]))->save();
}

function sycDue(string $method, Conversation $conv): bool
{
    return app(SilenceTracker::class)->{$method}()->pluck('id')->contains($conv->id);
}

// ---------------------------------------------------------------------------
// Seguimiento a las 16 h
// ---------------------------------------------------------------------------

it('manda el seguimiento a las 16 h si el último mensaje es nuestro', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 17);
    sycMsg($conv, 'outbound', 16.9);

    expect(sycDue('followupsDue', $conv))->toBeTrue();
});

it('no lo manda antes de las 16 h', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 15.9);
    sycMsg($conv, 'outbound', 15.8);

    expect(sycDue('followupsDue', $conv))->toBeFalse();
});

it('no lo manda si el último mensaje es del cliente: el pendiente es nuestro', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'outbound', 18);
    sycMsg($conv, 'inbound', 17);

    expect(sycDue('followupsDue', $conv))->toBeFalse();
});

it('no lo manda con la ventana de 24 h ya cerrada', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 25);
    sycMsg($conv, 'outbound', 24.9);

    expect(sycDue('followupsDue', $conv))->toBeFalse();
});

it('no lo manda si la conversación está derivada a un asesor', function () {
    $conv = sycConv(sycLead(), ['handoff_state' => Conversation::HANDOFF_ASSIGNED]);
    sycMsg($conv, 'inbound', 17);
    sycMsg($conv, 'outbound', 16.9);

    expect(sycDue('followupsDue', $conv))->toBeFalse();
});

it('sí lo manda con el agente apagado', function () {
    $conv = sycConv(sycLead(), ['ai_enabled' => false]);
    sycMsg($conv, 'inbound', 17);
    sycMsg($conv, 'outbound', 16.9, ['sender' => 'human']);

    expect(sycDue('followupsDue', $conv))->toBeTrue();
});

it('no lo manda para pedidos confirmados', function () {
    $conv = sycConv(sycLead('Cochabamba', 'cliente-confirmado'));
    sycMsg($conv, 'inbound', 17);
    sycMsg($conv, 'outbound', 16.9);

    expect(sycDue('followupsDue', $conv))->toBeFalse();
});

it('manda un solo seguimiento por silencio', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 17);
    sycMsg($conv, 'outbound', 16.9);

    app(SilenceTracker::class)->sendFollowup($conv);

    expect(sycDue('followupsDue', $conv->fresh()))->toBeFalse();
});

it('el texto es neutro, con el primer nombre en formato título y nunca en mayúsculas', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 17);
    sycMsg($conv, 'outbound', 16.9);

    $message = app(SilenceTracker::class)->sendFollowup($conv);

    expect($message->body)->toBe('Hola Juan 👋 ¿Te puedo ayudar en algo más? Cuando quieras, seguimos por aquí 🍷')
        ->and($message->sender)->toBe('ia')
        ->and($message->payload['followup'])->toBeTrue();

    Queue::assertPushed(SendMessage::class);
});

it('sin un nombre con letras saluda sin nombre', function () {
    $conv = sycConv(sycLead('Cochabamba', 'cliente-contactado', 'Pedido Club del Vino - Cliente'), ['wa_name' => '#️⃣#️⃣']);
    sycMsg($conv, 'inbound', 17);
    sycMsg($conv, 'outbound', 16.9);

    expect(app(SilenceTracker::class)->sendFollowup($conv)->body)->toStartWith('Hola 👋 ');
});

it('con la regla apagada no hay seguimientos ni cierres', function () {
    config(['whatsapp.auto_close.since' => null]);

    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);

    expect(sycDue('followupsDue', $conv))->toBeFalse()
        ->and(sycDue('closuresDue', $conv))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Cierre a las 72 h
// ---------------------------------------------------------------------------

it('cierra el pedido a las 72 h de nuestro último mensaje sin respuesta', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 72.1);

    expect(sycDue('closuresDue', $conv))->toBeTrue();
});

it('no lo cierra antes de las 72 h', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 71.9);

    expect(sycDue('closuresDue', $conv))->toBeFalse();
});

it('una plantilla posterior no reinicia los 3 días', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);
    sycMsg($conv, 'outbound', 1, ['type' => 'template', 'sender' => 'human']);

    expect(sycDue('closuresDue', $conv))->toBeTrue();
});

it('un mensaje nuestro que falló no cuenta: el cliente nunca lo recibió', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'outbound', 90);
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 75, ['status' => 'failed']);

    expect(sycDue('closuresDue', $conv))->toBeFalse();
});

it('una reacción del cliente cuenta como respuesta', function () {
    $conv = sycConv(sycLead());
    sycMsg($conv, 'outbound', 80);
    sycMsg($conv, 'inbound', 74, ['type' => 'reaction', 'body' => '👍']);

    expect(sycDue('closuresDue', $conv))->toBeFalse();
});

it('no cierra pedidos confirmados', function () {
    $conv = sycConv(sycLead('Cochabamba', 'cliente-confirmado'));
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);

    expect(sycDue('closuresDue', $conv))->toBeFalse();
});

it('solo hacia adelante: un mensaje anterior a la activación no cuenta', function () {
    config(['whatsapp.auto_close.since' => now()->subHours(1)->toDateTimeString()]);

    $conv = sycConv(sycLead());
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);

    expect(sycDue('closuresDue', $conv))->toBeFalse();
});

it('cierra en "Cliente sin interés" de SU pipeline, a nombre de Sistema y con la marca', function () {
    $leadId = sycLead('Cochabamba');
    $conv = sycConv($leadId);
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);

    expect(app(SilenceTracker::class)->close($conv))->toBeTrue();

    expect((int) DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id'))
        ->toBe(sycStage('Cochabamba', 'cliente-sin-inters'));

    $marca = DB::table('activities as a')
        ->join('lead_activities as la', 'la.activity_id', '=', 'a.id')
        ->where('la.lead_id', $leadId)
        ->where('a.title', SilenceTracker::MARKER_TITLE)
        ->first();

    expect($marca)->not->toBeNull()
        ->and((int) $marca->user_id)->toBe((int) config('whatsapp.auto_close.user_id'));

    // El cambio de etapa también quedó a nombre de Sistema, y la sesión se restauró.
    $cambio = DB::table('activities as a')
        ->join('lead_activities as la', 'la.activity_id', '=', 'a.id')
        ->where('la.lead_id', $leadId)
        ->where('a.id', '<', $marca->id)
        ->orderByDesc('a.id')
        ->first();

    expect((int) $cambio->user_id)->toBe((int) config('whatsapp.auto_close.user_id'))
        ->and(auth()->guard('user')->check())->toBeFalse();
});

it('el comando en modo simulación no escribe nada', function () {
    $leadId = sycLead();
    $conv = sycConv($leadId);
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);

    test()->artisan('whatsapp:seguimiento-y-cierre', ['--dry-run' => true])->assertSuccessful();

    expect((int) DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id'))
        ->toBe(sycStage('Cochabamba', 'cliente-contactado'));
});

it('el comando cierra lo que corresponde', function () {
    $leadId = sycLead();
    $conv = sycConv($leadId);
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);

    test()->artisan('whatsapp:seguimiento-y-cierre')->assertSuccessful();

    expect((int) DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id'))
        ->toBe(sycStage('Cochabamba', 'cliente-sin-inters'));
});

// ---------------------------------------------------------------------------
// Reapertura
// ---------------------------------------------------------------------------

it('reabre en "Cliente contactado" cuando el cliente vuelve a escribir', function () {
    $leadId = sycLead('Cochabamba');
    $conv = sycConv($leadId);
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);
    app(SilenceTracker::class)->close($conv);

    $phone = ltrim($conv->wa_phone, '+');

    (new IngestInboundPayload('cloud_api', [
        'entry' => [['changes' => [['value' => [
            'contacts' => [['profile' => ['name' => 'Perfil']]],
            'messages' => [['id' => 'wamid.syc'.random_int(1000, 9999), 'from' => $phone, 'type' => 'text', 'text' => ['body' => 'Hola, sigo interesado']]],
        ]]]]],
    ]))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    expect((int) DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id'))
        ->toBe(sycStage('Cochabamba', 'cliente-contactado'));
});

it('no reabre un pedido que un asesor cerró a mano', function () {
    $leadId = sycLead('Cochabamba');
    $conv = sycConv($leadId);
    sycMsg($conv, 'inbound', 80);
    sycMsg($conv, 'outbound', 73);
    app(SilenceTracker::class)->close($conv);

    // Después un asesor lo saca y lo vuelve a poner en "sin interés" desde el kanban.
    $repo = app(Webkul\Lead\Repositories\LeadRepository::class);
    $repo->update(['entity_type' => 'leads', 'lead_pipeline_stage_id' => sycStage('Cochabamba', 'cliente-contactado')], $leadId, ['lead_pipeline_stage_id']);
    $repo->update(['entity_type' => 'leads', 'lead_pipeline_stage_id' => sycStage('Cochabamba', 'cliente-sin-inters')], $leadId, ['lead_pipeline_stage_id']);

    expect(app(SilenceTracker::class)->reopenIfAutoClosed($conv->fresh()))->toBeFalse();
});

it('no reabre un pedido en "sin interés" que nunca cerró la regla', function () {
    $conv = sycConv(sycLead('Cochabamba', 'cliente-sin-inters'));

    expect(app(SilenceTracker::class)->reopenIfAutoClosed($conv))->toBeFalse();
});
