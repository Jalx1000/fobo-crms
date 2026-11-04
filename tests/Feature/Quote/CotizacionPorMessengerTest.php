<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\DataGrids\Quote\QuoteDataGrid;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Enviar la cotización por Messenger desde el módulo de cotizaciones.
 *
 * Mismo PDF y mismas protecciones que el envío por WhatsApp (sin reenvíos
 * seguidos, estado en el listado, actividad), pero por la conversación de
 * Messenger del contacto. Messenger no tiene plantillas: fuera de las 24 h no
 * se puede enviar y hay que decirlo.
 *
 * Contra la base del contenedor con transacciones; cola, disco, correo y HTTP
 * falseados: nada llega a Meta.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('public');
    Http::fake();
    Mail::fake();

    $this->actingAs(User::findOrFail(1), 'user');

    $producto = app(ProductRepository::class)->create([
        'entity_type' => 'products', 'name' => 'MS test '.uniqid(), 'sku' => 'TEST-MS-'.uniqid(), 'price' => 10, 'quantity' => 0,
    ]);

    $this->person = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Cliente MS '.uniqid(), 'emails' => [],
    ]));

    $this->quote = Event::fakeFor(fn () => app(QuoteRepository::class)->create([
        'entity_type' => 'quotes', 'subject' => 'Cotización MS '.uniqid(),
        'person_id'   => $this->person->id, 'user_id' => 1,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 100, 'grand_total' => 100,
        'items'       => [[
            'product_id' => $producto->id, 'sku' => $producto->sku,
            'name'       => 'Producto MS', 'quantity' => 1, 'price' => 100, 'total' => 100,
        ]],
    ]));
});

function msConversacion($test, array $extra = []): Conversation
{
    return Conversation::create(array_merge([
        'gateway'                  => 'messenger',
        'provider_conversation_id' => 'PSID-MS-'.uniqid(),
        'provider_account_id'      => '155103561021423',
        'wa_name'                  => 'Cliente MS',
        'ai_enabled'               => false,
        'status'                   => 'open',
        'person_id'                => $test->person->id,
        'last_inbound_at'          => now()->subHour(),
    ], $extra));
}

function msEnviar($test)
{
    return $test->withHeaders(['Accept' => 'application/json'])
        ->post(route('admin.quotes.messenger', $test->quote->id));
}

it('envía el PDF por la conversación de Messenger del contacto', function () {
    $conv = msConversacion($this);

    msEnviar($this)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Messenger'));

    $mensaje = DB::table('whatsapp_messages')->where('conversation_id', $conv->id)->first();

    expect($mensaje)->not->toBeNull()
        ->and($mensaje->type)->toBe('document')
        ->and($mensaje->direction)->toBe('outbound')
        ->and(json_decode($mensaje->payload, true)['quote_id'])->toBe($this->quote->id)
        ->and(json_decode($mensaje->payload, true))->not->toHaveKey('template');

    Queue::assertPushed(SendMessage::class);
});

it('no usa una conversación de WhatsApp aunque sea más reciente', function () {
    $messenger = msConversacion($this, ['last_message_at' => now()->subDay()]);
    $whatsapp = Conversation::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+5916'.random_int(1000000, 9999999), 'ai_enabled' => false,
        'status'  => 'open', 'person_id' => $this->person->id, 'last_inbound_at' => now(), 'last_message_at' => now(),
    ]);

    msEnviar($this)->assertOk();

    expect(DB::table('whatsapp_messages')->where('conversation_id', $messenger->id)->count())->toBe(1)
        ->and(DB::table('whatsapp_messages')->where('conversation_id', $whatsapp->id)->count())->toBe(0);
});

it('sin conversación de Messenger avisa con un motivo claro', function () {
    msEnviar($this)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Messenger'));

    Queue::assertNothingPushed();
});

it('fuera de las 24 h no envía: Messenger no tiene plantillas', function () {
    $conv = msConversacion($this, ['last_inbound_at' => now()->subDays(2)]);

    msEnviar($this)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '24'));

    expect(DB::table('whatsapp_messages')->where('conversation_id', $conv->id)->count())->toBe(0);
});

it('no reenvía la misma cotización enseguida', function () {
    $conv = msConversacion($this);

    msEnviar($this)->assertOk();
    msEnviar($this)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya se envió'));

    expect(DB::table('whatsapp_messages')->where('conversation_id', $conv->id)->count())->toBe(1);
});

it('deja la actividad diciendo que salió por Messenger', function () {
    msConversacion($this);

    msEnviar($this)->assertOk();

    $titulo = DB::table('activities')->join('person_activities', 'person_activities.activity_id', '=', 'activities.id')
        ->where('person_activities.person_id', $this->person->id)->orderByDesc('activities.id')->value('activities.title');

    expect($titulo)->toContain('#'.$this->quote->id)->toContain('Messenger');
});

/*
| Un solo botón «Enviar cotización»: el canal lo elige el sistema, por donde
| el cliente escribió último. Messenger cerrado (más de 24 h) → WhatsApp, que
| sí puede usar plantilla.
*/
function msEnviarUnico($test)
{
    return $test->withHeaders(['Accept' => 'application/json'])
        ->post(route('admin.quotes.send', $test->quote->id));
}

function msWhatsapp($test, $ultimoMensaje): Conversation
{
    return Conversation::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+5916'.random_int(1000000, 9999999), 'ai_enabled' => false,
        'status'  => 'open', 'person_id' => $test->person->id, 'last_inbound_at' => $ultimoMensaje, 'last_message_at' => $ultimoMensaje,
    ]);
}

function msEnviados(Conversation $conv): int
{
    return DB::table('whatsapp_messages')->where('conversation_id', $conv->id)->count();
}

it('el botón único sale por Messenger si el cliente escribió último por ahí', function () {
    $messenger = msConversacion($this, ['last_inbound_at' => now()->subMinutes(5)]);
    $whatsapp = msWhatsapp($this, now()->subHours(3));

    msEnviarUnico($this)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Messenger'));

    expect(msEnviados($messenger))->toBe(1)->and(msEnviados($whatsapp))->toBe(0);
});

it('el botón único sale por WhatsApp si el cliente escribió último por ahí', function () {
    $messenger = msConversacion($this, ['last_inbound_at' => now()->subHours(3)]);
    $whatsapp = msWhatsapp($this, now()->subMinutes(5));

    msEnviarUnico($this)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'WhatsApp'));

    expect(msEnviados($messenger))->toBe(0)->and(msEnviados($whatsapp))->toBe(1);
});

it('el botón único usa WhatsApp cuando Messenger ya pasó las 24 h', function () {
    $messenger = msConversacion($this, ['last_inbound_at' => now()->subDays(2)]);
    $whatsapp = msWhatsapp($this, now()->subDays(5));

    msEnviarUnico($this);

    expect(msEnviados($messenger))->toBe(0);
});

it('el botón único sin Messenger sale por WhatsApp como siempre', function () {
    $whatsapp = msWhatsapp($this, now()->subMinutes(5));

    msEnviarUnico($this)->assertOk();

    expect(msEnviados($whatsapp))->toBe(1);
});

it('el listado tiene un solo botón de enviar', function () {
    $grid = app(QuoteDataGrid::class);
    $grid->prepareActions();

    $acciones = collect($grid->getActions())->map(fn ($a) => $a->index)->all();

    expect($acciones)->toContain('send')
        ->not->toContain('whatsapp')
        ->not->toContain('messenger');
});

it('una cotización con precio pendiente no se envía por ningún canal', function () {
    DB::table('quotes')->where('id', $this->quote->id)->update(['grand_total' => 0, 'sub_total' => 0]);
    $messenger = msConversacion($this);
    $whatsapp = msWhatsapp($this, now()->subMinutes(5));

    msEnviarUnico($this)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'precio'));
    msEnviar($this)->assertStatus(422);
    $this->withHeaders(['Accept' => 'application/json'])->post(route('admin.quotes.whatsapp', $this->quote->id))->assertStatus(422);

    expect(msEnviados($messenger) + msEnviados($whatsapp))->toBe(0);
});
