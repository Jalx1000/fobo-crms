<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Services\CiudadCliente;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Services\Responsables;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\CiudadDesdeChat;

/**
 * La ciudad que el cliente contesta en el chat queda guardada y decide el asesor.
 *
 * Caso real (13/10): todo se derivaba a Mariano. El contacto y el pedido nacen
 * con el asesor por defecto porque todavía no hay ciudad, y la ciudad que el
 * cliente contestaba al menú «¿De qué ciudad nos escribe?» no se guardaba en
 * ningún lado (139 conversaciones la dijeron; 42 contactos la tenían). Al
 * derivar, el dueño ya era Mariano y ahí se quedaba.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    Queue::fake();

    $this->defecto = app(Responsables::class)->asesorPorDefecto();

    $this->persona = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Chat ciudad '.uniqid(),
    ]));
    DB::table('persons')->where('id', $this->persona->id)->update(['user_id' => $this->defecto->id]);

    $this->lead = DB::table('leads')->insertGetId([
        'title'                  => 'Chat ciudad '.uniqid(), 'person_id' => $this->persona->id, 'user_id' => $this->defecto->id, 'status' => 1,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', 'no-atendidos')->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    $this->conv = Conversation::create([
        'gateway'         => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Chat ciudad',
        'ai_enabled'      => true, 'status' => 'open', 'person_id' => $this->persona->id, 'lead_id' => $this->lead,
        'last_inbound_at' => now(),
    ]);
});

function preguntaCiudad($conv): void
{
    Message::create([
        'conversation_id' => $conv->id, 'direction' => 'outbound', 'type' => 'interactive', 'sender' => 'ia', 'status' => 'sent',
        'body'            => '¿De qué ciudad nos escribe?',
        'payload'         => ['interactive' => ['format' => 'list', 'sections' => [['rows' => [
            ['id' => 'Santa Cruz', 'title' => 'Santa Cruz'], ['id' => 'Cochabamba', 'title' => 'Cochabamba'], ['id' => 'Otra ciudad', 'title' => 'Otra ciudad'],
        ]]]]],
    ]);
}

function contesta($conv, string $texto): void
{
    $m = Message::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'type' => 'text', 'sender' => 'contact',
        'status'          => 'delivered', 'body' => $texto,
    ]);

    app(CiudadDesdeChat::class)->revisar($m);
}

function leadCiudad(int $id)
{
    return DB::table('leads')->find($id);
}

it('guarda la ciudad que contestó en el contacto y el pedido', function () {
    preguntaCiudad($this->conv);
    contesta($this->conv, 'Cochabamba');

    expect(app(CiudadCliente::class)->de('persons', $this->persona->id))->toBe('Cochabamba')
        ->and(app(CiudadCliente::class)->de('leads', $this->lead))->toBe('Cochabamba');
});

it('reconoce formas cortas: cbba, sc, de santa cruz', function () {
    preguntaCiudad($this->conv);
    contesta($this->conv, 'cbba');

    expect(app(CiudadCliente::class)->de('persons', $this->persona->id))->toBe('Cochabamba');
});

it('el pedido deja de ser del asesor por defecto: pasa a quien atiende esa ciudad', function () {
    $esperado = DB::table('producto_responsables')->where('ciudad', 'Cochabamba')->value('user_id');

    preguntaCiudad($this->conv);
    contesta($this->conv, 'Cochabamba');

    expect((int) leadCiudad($this->lead)->user_id)->toBe((int) $esperado)
        ->and((int) DB::table('persons')->where('id', $this->persona->id)->value('user_id'))->toBe((int) $esperado);
});

it('el pedido pasa al pipeline de su ciudad, en la misma etapa', function () {
    preguntaCiudad($this->conv);
    contesta($this->conv, 'Cochabamba');

    $lead = leadCiudad($this->lead);

    expect(DB::table('lead_pipelines')->where('id', $lead->lead_pipeline_id)->value('name'))->toBe('Cochabamba')
        ->and(DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code'))->toBe('no-atendidos');
});

it('una derivación abierta a nombre del asesor por defecto pasa al nuevo asesor', function () {
    $this->conv->forceFill([
        'handoff_state'    => Conversation::HANDOFF_ASSIGNED, 'handoff_requested_at' => now(),
        'assigned_user_id' => $this->defecto->id, 'ai_enabled' => false,
    ])->save();

    preguntaCiudad($this->conv);
    contesta($this->conv, 'Cochabamba');

    expect((int) $this->conv->fresh()->assigned_user_id)->toBe((int) leadCiudad($this->lead)->user_id)
        ->and((int) $this->conv->fresh()->assigned_user_id)->not->toBe((int) $this->defecto->id);
});

it('si el pedido ya es de otro asesor, no se lo saca', function () {
    $otro = (int) DB::table('producto_responsables')->where('ciudad', 'La Paz')->value('user_id');
    DB::table('leads')->where('id', $this->lead)->update(['user_id' => $otro]);

    preguntaCiudad($this->conv);
    contesta($this->conv, 'Cochabamba');

    expect((int) leadCiudad($this->lead)->user_id)->toBe($otro);
});

it('sin la pregunta de ciudad, un texto con una ciudad no se toma', function () {
    contesta($this->conv, 'Cochabamba');

    expect(app(CiudadCliente::class)->de('persons', $this->persona->id))->toBeNull();
});

it('«Otra ciudad» no guarda nada', function () {
    preguntaCiudad($this->conv);
    contesta($this->conv, 'Otra ciudad');

    expect(app(CiudadCliente::class)->de('persons', $this->persona->id))->toBeNull()
        ->and((int) leadCiudad($this->lead)->user_id)->toBe((int) $this->defecto->id);
});

/*
| Reparto según la tabla del 13/10: Bulmaro (Stretch Film, Bolivia), Eduardo
| (Magia Verde, Bolivia), Fabio (Hules, Santa Cruz), Mauricio (todo,
| Cochabamba), Gabriela (todo, La Paz), Mariano (todo, Bolivia). El producto se
| toma de lo que el cliente eligió o escribió en el chat.
*/
function eligeProducto($conv, string $titulo): void
{
    Message::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'type' => 'text', 'sender' => 'contact',
        'status'          => 'delivered', 'body' => $titulo,
    ]);
}

function idDe(string $nombre): int
{
    return (int) DB::table('users')->where('name', $nombre)->value('id');
}

it('Santa Cruz + Hules va a Fabio', function () {
    eligeProducto($this->conv, '🔵 Hules');
    preguntaCiudad($this->conv);
    contesta($this->conv, 'Santa Cruz');

    expect((int) leadCiudad($this->lead)->user_id)->toBe(idDe('Fabio Sandoval'));
});

it('Santa Cruz + Stretch Film va a la cobertura nacional de Stretch (Bulmaro o Mariano), no a Fabio', function () {
    eligeProducto($this->conv, '⚪ Stretch Film');
    preguntaCiudad($this->conv);
    contesta($this->conv, 'Santa Cruz');

    expect((int) leadCiudad($this->lead)->user_id)->toBeIn([idDe('Bulmaro Vaca'), idDe('Mariano Pinell')]);
});

it('Santa Cruz + una bolsa del anuncio va a la cobertura nacional de Magia Verde (Eduardo o Mariano)', function () {
    eligeProducto($this->conv, '¿Cuánto cuesta la bolsa XXL?');
    preguntaCiudad($this->conv);
    contesta($this->conv, 'Santa Cruz');

    expect((int) leadCiudad($this->lead)->user_id)->toBeIn([idDe('Eduardo Lujan'), idDe('Mariano Pinell')]);
});

it('Santa Cruz sin producto conocido no va a Fabio (solo atiende Hules)', function () {
    preguntaCiudad($this->conv);
    contesta($this->conv, 'Santa Cruz');

    expect((int) leadCiudad($this->lead)->user_id)->not->toBe(idDe('Fabio Sandoval'));
});

it('La Paz + Magia Verde va a Gabriela', function () {
    eligeProducto($this->conv, '🟢 Bolsas Magia Verde');
    preguntaCiudad($this->conv);
    contesta($this->conv, 'La Paz');

    expect((int) leadCiudad($this->lead)->user_id)->toBe(idDe('Gabriela Marconi'));
});
