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
use Webkul\Whatsapp\Models\Conversation;

/**
 * «Cotización enviada» en Cotizaciones: el estado del envío por WhatsApp en el
 * listado y la actividad en la oportunidad y el contacto.
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

    $this->actingAs($this->asesor = User::findOrFail(1), 'user');

    $producto = app(ProductRepository::class)->create([
        'entity_type' => 'products', 'name' => 'EE test '.uniqid(), 'sku' => 'TEST-EE-'.uniqid(), 'price' => 10, 'quantity' => 0,
    ]);

    $telefono = '5917'.random_int(1000000, 9999999);

    $this->person = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type'     => 'persons', 'name' => 'Cliente EE '.uniqid(), 'emails' => [],
        'contact_numbers' => [['value' => $telefono, 'label' => 'work']],
    ]));

    $this->conversation = Conversation::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+'.$telefono, 'ai_enabled' => false,
        'status'  => 'open', 'person_id' => $this->person->id, 'last_inbound_at' => now(),
    ]);

    $this->leadId = DB::table('leads')->insertGetId([
        'title'                  => 'Oportunidad EE', 'user_id' => 1, 'person_id' => $this->person->id, 'status' => 1,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->value('id'),
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    $this->quote = Event::fakeFor(fn () => app(QuoteRepository::class)->create([
        'entity_type' => 'quotes', 'subject' => 'Cotización EE '.uniqid(),
        'person_id'   => $this->person->id, 'user_id' => 1,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 100, 'grand_total' => 100,
        'items'       => [[
            'product_id' => $producto->id, 'sku' => $producto->sku,
            'name'       => 'Producto EE', 'quantity' => 1, 'price' => 100, 'total' => 100,
        ]],
    ]));

    DB::table('lead_quotes')->insert(['lead_id' => $this->leadId, 'quote_id' => $this->quote->id]);
});

function eeFila($test)
{
    return app(QuoteDataGrid::class)->prepareQueryBuilder()->where('quotes.id', $test->quote->id)->first();
}

function eeMensaje($test, string $status, array $extra = []): void
{
    DB::table('whatsapp_messages')->insert(array_merge([
        'conversation_id' => $test->conversation->id, 'direction' => 'outbound', 'type' => 'document',
        'status'          => $status, 'sender' => 'human', 'media_name' => 'otro.pdf',
        'payload'         => json_encode(['quote_id' => $test->quote->id]),
        'created_at'      => now(), 'updated_at' => now(),
    ], $extra));
}

it('sin envíos la cotización figura como no enviada', function () {
    $fila = eeFila($this);

    expect($fila->whatsapp_status)->toBeNull()
        ->and((int) $fila->whatsapp_envios)->toBe(0)
        ->and(QuoteDataGrid::estadoWhatsapp($fila))->toContain('No enviada');
});

it('muestra el estado del último envío y cuántas veces se envió', function () {
    eeMensaje($this, 'read', ['created_at' => now()->subMinutes(30)]);
    eeMensaje($this, 'delivered');

    $fila = eeFila($this);

    expect($fila->whatsapp_status)->toBe('delivered')
        ->and((int) $fila->whatsapp_envios)->toBe(2)
        ->and(QuoteDataGrid::estadoWhatsapp($fila))->toContain('Entregada')->toContain('×2');
});

it('reconoce los envíos viejos por el nombre del PDF', function () {
    eeMensaje($this, 'sent', [
        'payload'    => json_encode(['media_disk_path' => 'x']),
        'media_name' => 'Cotizacion-'.$this->quote->id.'-algo.pdf',
    ]);

    expect(eeFila($this)->whatsapp_status)->toBe('sent');
});

it('cada estado tiene su etiqueta', function (string $status, string $texto) {
    eeMensaje($this, $status);

    expect(QuoteDataGrid::estadoWhatsapp(eeFila($this)))->toContain($texto);
})->with([
    ['queued', 'Enviando'],
    ['sent', 'Enviada'],
    ['delivered', 'Entregada'],
    ['read', 'Leída'],
    ['failed', 'Falló'],
]);

it('el listado trae la columna WhatsApp', function () {
    $grid = app(QuoteDataGrid::class);
    $grid->prepareColumns();

    expect(collect($grid->getColumns())->map(fn ($c) => is_array($c) ? $c['index'] : $c->getIndex())->all())
        ->toContain('whatsapp');
});

it('enviar por WhatsApp deja una actividad en la oportunidad y el contacto', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('admin.quotes.whatsapp', $this->quote->id))
        ->assertOk();

    $deLead = DB::table('activities')->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
        ->where('lead_activities.lead_id', $this->leadId)->pluck('activities.title');

    $dePersona = DB::table('activities')->join('person_activities', 'person_activities.activity_id', '=', 'activities.id')
        ->where('person_activities.person_id', $this->person->id)->pluck('activities.title');

    foreach ([$deLead, $dePersona] as $titulos) {
        expect($titulos->first(fn ($t) => str_contains($t, '#'.$this->quote->id) && str_contains($t, 'WhatsApp')))->not->toBeNull();
    }
});

it('el listado real (paginado, como lo pide el navegador) trae la columna con el estado', function () {
    eeMensaje($this, 'delivered');

    $respuesta = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
        ->get(route('admin.quotes.index', ['sort' => ['column' => 'id', 'order' => 'desc']]))
        ->assertOk();

    $fila = collect($respuesta->json('records'))->firstWhere('id', $this->quote->id);

    expect($fila)->not->toBeNull()
        ->and($fila['whatsapp'])->toContain('Entregada');
});
