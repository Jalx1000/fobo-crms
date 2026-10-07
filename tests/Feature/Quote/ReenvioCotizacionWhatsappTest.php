<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Enviar la misma cotización por WhatsApp dos veces seguidas.
 *
 * Caso real (2026-10-07, contacto P.S.A, cotización #2198): el botón del
 * listado se confirmó 5 veces en 38 segundos y el cliente recibió el PDF 5
 * veces. El servidor ahora rechaza un reenvío de la misma cotización a la
 * misma conversación dentro de la ventana configurada.
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

    config(['whatsapp.quote_resend_minutes' => 10]);

    $this->actingAs(User::findOrFail(1), 'user');

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products', 'name' => 'RQ test '.uniqid(), 'sku' => 'TEST-RQ-'.uniqid(), 'price' => 10, 'quantity' => 0,
    ]);

    $this->telefono = '5917'.random_int(1000000, 9999999);

    $this->person = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type'     => 'persons',
        'name'            => 'Cliente RQ '.uniqid(),
        'emails'          => [],
        'contact_numbers' => [['value' => $this->telefono, 'label' => 'work']],
    ]));

    // Ventana de 24 h abierta: el envío es un documento común, sin plantilla.
    $this->conversation = Conversation::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+'.$this->telefono, 'ai_enabled' => false,
        'status'  => 'open', 'person_id' => $this->person->id, 'last_inbound_at' => now(),
    ]);
});

function rqCotizacion($test)
{
    return Event::fakeFor(fn () => app(QuoteRepository::class)->create([
        'entity_type' => 'quotes', 'subject' => 'Cotización RQ '.uniqid(),
        'person_id'   => $test->person->id, 'user_id' => 1,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 100, 'grand_total' => 100,
        'items'       => [[
            'product_id' => $test->producto->id, 'sku' => $test->producto->sku,
            'name'       => 'Producto RQ', 'quantity' => 1, 'price' => 100, 'total' => 100,
        ]],
    ]));
}

function rqEnviar($quote)
{
    return test()->withHeaders(['Accept' => 'application/json'])
        ->post(route('admin.quotes.whatsapp', $quote->id));
}

function rqEnviados($test): int
{
    return DB::table('whatsapp_messages')->where('conversation_id', $test->conversation->id)->count();
}

it('el primer envío sale y guarda a qué cotización corresponde', function () {
    $quote = rqCotizacion($this);

    rqEnviar($quote)->assertOk();

    $payload = json_decode(DB::table('whatsapp_messages')->where('conversation_id', $this->conversation->id)->value('payload'), true);

    expect(rqEnviados($this))->toBe(1)
        ->and($payload['quote_id'])->toBe($quote->id);
});

it('rechaza reenviar la misma cotización a la misma conversación enseguida', function () {
    $quote = rqCotizacion($this);

    rqEnviar($quote)->assertOk();

    rqEnviar($quote)
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'ya se envió') && str_contains($m, '#'.$quote->id));

    expect(rqEnviados($this))->toBe(1);
});

it('otra cotización al mismo cliente sí sale', function () {
    rqEnviar(rqCotizacion($this))->assertOk();
    rqEnviar(rqCotizacion($this))->assertOk();

    expect(rqEnviados($this))->toBe(2);
});

it('pasada la ventana se puede volver a enviar', function () {
    $quote = rqCotizacion($this);

    rqEnviar($quote)->assertOk();

    $this->travel(11)->minutes();

    rqEnviar($quote)->assertOk();

    expect(rqEnviados($this))->toBe(2);
});

it('un envío que falló no bloquea el reintento', function () {
    $quote = rqCotizacion($this);

    rqEnviar($quote)->assertOk();

    DB::table('whatsapp_messages')->where('conversation_id', $this->conversation->id)->update(['status' => 'failed']);

    rqEnviar($quote)->assertOk();

    expect(rqEnviados($this))->toBe(2);
});
