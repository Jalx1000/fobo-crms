<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Jobs\AsignarSinCiudad;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Pedidos sin ciudad: a la hora, el asesor nacional de su producto
 * (spec docs/specs/asignar-sin-ciudad.md). Sin derivar, IA encendida, sin correos.
 */
beforeEach(function () {
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->fabio = User::where('name', 'Fabio Sandoval')->firstOrFail();
    $this->sinCiudad = (int) DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');
});

/**
 * Un pedido sin ciudad, con su contacto y su conversación, de $asesor, con $sku.
 */
function pedidoSinCiudad($test, User $asesor, ?string $sku, string $etapa = 'no-atendidos', int $pipeline = 0): array
{
    $pipeline = $pipeline ?: $test->sinCiudad;
    $persona = DB::table('persons')->insertGetId(['name' => 'Sin ciudad '.uniqid(), 'user_id' => $asesor->id, 'created_at' => now(), 'updated_at' => now()]);
    $lead = DB::table('leads')->insertGetId([
        'title'                  => 'Sin ciudad '.uniqid(), 'person_id' => $persona, 'user_id' => $asesor->id, 'status' => 1,
        'lead_pipeline_id'       => $pipeline,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->where('code', $etapa)->value('id'),
        'created_at'             => now()->subHours(2), 'updated_at' => now()->subHours(2),
    ]);

    if ($sku) {
        DB::table('lead_products')->insert([
            'lead_id'  => $lead, 'product_id' => DB::table('products')->where('sku', $sku)->value('id'),
            'quantity' => 0, 'price' => 0, 'amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Sin ciudad',
        'ai_enabled' => true, 'status' => 'open', 'person_id' => $persona, 'lead_id' => $lead, 'last_message_at' => now(),
    ]);

    return ['lead' => $lead, 'persona' => $persona, 'conv' => $conv];
}

function asignar(int $lead): void
{
    (new AsignarSinCiudad($lead))->handle(app(\Webkul\Product\Services\Responsables::class));
}

function asesorDe(int $lead): int
{
    return (int) DB::table('leads')->where('id', $lead)->value('user_id');
}

it('pasa al asesor nacional de su producto, con su contacto', function () {
    $p = pedidoSinCiudad($this, $this->bulmaro, 'CM_00002'); // Magia Verde → Eduardo

    asignar($p['lead']);

    expect(asesorDe($p['lead']))->toBe($this->eduardo->id)
        ->and((int) DB::table('persons')->where('id', $p['persona'])->value('user_id'))->toBe($this->eduardo->id);
});

it('si ya es del asesor de su producto, no cambia', function () {
    $p = pedidoSinCiudad($this, $this->bulmaro, 'CM_00001'); // Stretch → Bulmaro

    asignar($p['lead']);

    expect(asesorDe($p['lead']))->toBe($this->bulmaro->id);
});

it('sin producto: si ya es de un asesor nacional no cambia; si no, va al turno nacional', function () {
    $nacional = pedidoSinCiudad($this, $this->eduardo, null);
    asignar($nacional['lead']);
    expect(asesorDe($nacional['lead']))->toBe($this->eduardo->id);

    $local = pedidoSinCiudad($this, $this->fabio, null); // Fabio atiende solo Santa Cruz
    asignar($local['lead']);
    expect(asesorDe($local['lead']))->toBeIn([$this->bulmaro->id, $this->eduardo->id]);
});

it('no hace nada si el pedido ya no está esperando', function () {
    // Dijo su ciudad: ya no está en «Sin ciudad».
    $conCiudad = pedidoSinCiudad($this, $this->bulmaro, 'CM_00002', 'no-atendidos', 1);
    // Ya no está en NO ATENDIDOS.
    $avanzado = pedidoSinCiudad($this, $this->bulmaro, 'CM_00002', 'solicitud-recibida');
    // Un asesor ya le escribió.
    $atendido = pedidoSinCiudad($this, $this->bulmaro, 'CM_00002');
    DB::table('whatsapp_messages')->insert([
        'conversation_id' => $atendido['conv']->id, 'direction' => 'outbound', 'sender' => 'human', 'type' => 'text',
        'body'            => 'Hola', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Tiene una derivación abierta.
    $derivado = pedidoSinCiudad($this, $this->bulmaro, 'CM_00002');
    $derivado['conv']->forceFill(['handoff_state' => Conversation::HANDOFF_ASSIGNED, 'assigned_user_id' => $this->bulmaro->id, 'handoff_requested_at' => now()])->save();

    foreach ([$conCiudad, $avanzado, $atendido, $derivado] as $p) {
        asignar($p['lead']);
        expect(asesorDe($p['lead']))->toBe($this->bulmaro->id);
    }
});

it('nunca deriva, nunca apaga la IA y nunca manda correos', function () {
    Mail::fake();
    $p = pedidoSinCiudad($this, $this->bulmaro, 'CM_00002');

    asignar($p['lead']);

    $conv = $p['conv']->fresh();
    expect($conv->handoff_state)->toBeNull()
        ->and((bool) $conv->ai_enabled)->toBeTrue();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('crear un pedido en «Sin ciudad» agenda la asignación a la hora; en otra ciudad, no', function () {
    Queue::fake();

    $sinCiudad = pedidoSinCiudad($this, $this->bulmaro, null);
    Event::dispatch('lead.create.after', \Webkul\Lead\Models\Lead::find($sinCiudad['lead']));

    $santaCruz = pedidoSinCiudad($this, $this->bulmaro, null, 'no-atendidos', 1);
    Event::dispatch('lead.create.after', \Webkul\Lead\Models\Lead::find($santaCruz['lead']));

    Queue::assertPushed(AsignarSinCiudad::class, fn ($job) => $job->leadId === $sinCiudad['lead']
        && $job->delay instanceof \DateTimeInterface
        && abs(now()->addMinutes(60)->diffInSeconds($job->delay)) < 5);
    Queue::assertNotPushed(AsignarSinCiudad::class, fn ($job) => $job->leadId === $santaCruz['lead']);
});
