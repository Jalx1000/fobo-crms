<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Models\Product;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * Recotizar: crear una cotización nueva partiendo de una anterior del mismo
 * pedido.
 *
 * Se copian el cliente y las condiciones comerciales —que casi nunca cambian—
 * y NO los productos, que son justamente lo que se está recotizando. La validez
 * arranca de nuevo: la cotización nueva vence a los 10 días de hoy, no cuando
 * vencía la vieja.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Recotiza '.uniqid(), 'emails' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->lead = DB::table('leads')->insertGetId([
        'title'                  => 'Recotiza '.uniqid(),
        'person_id'              => $this->persona,
        'user_id'                => 1,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->value('id'),
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    $opcion = fn (string $code, string $nombre) => app(AttributeRepository::class)
        ->findOneWhere(['entity_type' => 'quotes', 'code' => $code])
        ->options->firstWhere('name', $nombre)?->id;

    $this->anterior = app(QuoteRepository::class)->create([
        'entity_type'    => 'quotes',
        'subject'        => 'Hules negros para el hotel',
        'person_id'      => $this->persona,
        'user_id'        => 1,
        // Vencida hace rato: la nueva no hereda esta fecha.
        'expired_at'     => now()->subDays(20)->toDateString(),
        'sub_total'      => 200,
        'grand_total'    => 200,
        'nit'            => '1234567019',
        'razon_social'   => 'Hotel Las Palmas',
        'marca'          => $opcion('marca', 'Magia Verde'),
        'forma_pago'     => $opcion('forma_pago', 'Crédito 30 días'),
        'tiempo_entrega' => '20 días hábiles',
        'ciudad'         => $opcion('ciudad', 'Tarija'),
        'items'          => [[
            'product_id' => Product::where('sku', 'CM_00003')->value('id'),
            'sku' => 'CM_00003', 'name' => 'Hules', 'quantity' => 8, 'price' => 25, 'total' => 200,
        ]],
    ]);

    $this->anterior->leads()->syncWithoutDetaching([$this->lead]);
});

afterEach(function () {
    DB::table('quote_items')->where('quote_id', $this->anterior->id)->delete();
});

it('la pestaña de cotizaciones ofrece recotizar desde la última', function () {
    $html = test()->get(route('admin.leads.view', $this->lead))->assertOk()->getContent();

    expect($html)->toContain('Recotizar')
        ->and($html)->toContain('copiar_de=');
});

it('el formulario arranca con el cliente y las condiciones de la anterior', function () {
    $html = test()->get(route('admin.quotes.create', $this->lead).'?from=lead&copiar_de='.$this->anterior->id)
        ->assertOk()->getContent();

    expect($html)->toContain('1234567019')
        ->and($html)->toContain('Hotel Las Palmas')
        ->and($html)->toContain('20 días hábiles');
});

it('la cotización nueva vence a los 10 días de hoy, no cuando vencía la anterior', function () {
    $html = test()->get(route('admin.quotes.create', $this->lead).'?from=lead&copiar_de='.$this->anterior->id)
        ->assertOk()->getContent();

    expect($html)->toContain('value="'.now()->addDays((int) config('cotizacion.validez_dias', 10))->toDateString().'"')
        ->and($html)->not->toContain('value="'.now()->subDays(20)->toDateString().'"');
});

it('los productos no se copian, ni el asunto que los describe', function () {
    $html = test()->get(route('admin.quotes.create', $this->lead).'?from=lead&copiar_de='.$this->anterior->id)
        ->assertOk()->getContent();

    expect($html)->not->toContain('CM_00003')
        ->and($html)->not->toContain('Hules negros para el hotel');
});

it('sin copiar_de el formulario sigue arrancando limpio', function () {
    $html = test()->get(route('admin.quotes.create', $this->lead).'?from=lead')->assertOk()->getContent();

    expect($html)->not->toContain('Hotel Las Palmas')
        ->and($html)->not->toContain('1234567019');
});
