<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Product\Models\Product;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\Whatsapp\Models\Conversation;

/**
 * La ciudad del cliente y la sucursal de la cotización.
 *
 * Dos arreglos del mismo malentendido: `sucursal` era un select con ciudades
 * adentro, así que no había dónde anotar de qué planta sale el producto ni de
 * qué ciudad es el cliente. Ahora la sucursal son los almacenes de verdad y la
 * ciudad es un atributo propio, igual en contacto, oportunidad y cotización, que
 * se copia solo entre las tres.
 */
uses(DatabaseTransactions::class);

function opcionCiudad(string $entityType, string $nombre): int
{
    $attribute = app(AttributeRepository::class)
        ->findWhere(['entity_type' => $entityType, 'code' => 'ciudad'])
        ->first();

    return $attribute->options->firstWhere('name', $nombre)->id;
}

function ciudadDe(string $entityType, int $id): ?string
{
    $attribute = app(AttributeRepository::class)
        ->findWhere(['entity_type' => $entityType, 'code' => 'ciudad'])
        ->first();

    $valor = DB::table('attribute_values')
        ->where('entity_type', $entityType)
        ->where('entity_id', $id)
        ->where('attribute_id', $attribute->id)
        ->value('integer_value');

    return $valor ? $attribute->options->firstWhere('id', (int) $valor)?->name : null;
}

function sucursalDe(int $quoteId): ?int
{
    $attribute = app(AttributeRepository::class)
        ->findWhere(['entity_type' => 'quotes', 'code' => 'sucursal'])
        ->first();

    $valor = DB::table('attribute_values')
        ->where('entity_type', 'quotes')
        ->where('entity_id', $quoteId)
        ->where('attribute_id', $attribute->id)
        ->value('integer_value');

    return $valor ? (int) $valor : null;
}

function personaCiudad(?string $ciudad = null)
{
    return app(PersonRepository::class)->create(array_filter([
        'entity_type' => 'persons',
        'name'        => 'Ciudad test '.uniqid(),
        'ciudad'      => $ciudad ? opcionCiudad('persons', $ciudad) : null,
    ]));
}

/**
 * Una cotización como la deja la pantalla: el repositorio la crea y el
 * controlador dispara el evento. Sin el evento no hay conciliación, y es el
 * controlador —no el repositorio— el que lo emite, igual que para el NIT.
 */
function cotizacionCiudad(array $extra)
{
    $quote = app(QuoteRepository::class)->create(array_merge([
        'entity_type' => 'quotes',
        'subject'     => 'Ciudad test '.uniqid(),
        'expired_at'  => now()->addDays(10)->toDateString(),
        'user_id'     => 1,
        'sub_total'   => 0,
        'grand_total' => 0,
        'items'       => [],
    ], $extra));

    Event::dispatch('quote.create.after', $quote);

    return $quote;
}

/**
 * Lo mismo para una oportunidad.
 */
function oportunidadCiudad(array $extra)
{
    $lead = app(LeadRepository::class)->create(array_merge([
        'entity_type'            => 'leads',
        'title'                  => 'Ciudad lead '.uniqid(),
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->value('id'),
        'user_id'                => 1,
    ], $extra));

    Event::dispatch('lead.create.after', $lead);

    return $lead;
}

it('las tres entidades comparten las mismas opciones de ciudad', function () {
    foreach (['persons', 'leads', 'quotes'] as $entidad) {
        $attribute = app(AttributeRepository::class)
            ->findWhere(['entity_type' => $entidad, 'code' => 'ciudad'])
            ->first();

        expect($attribute)->not->toBeNull("falta el atributo ciudad en {$entidad}");
        expect($attribute->options->pluck('name')->all())->toBe(config('cotizacion.ciudades_cliente'));
    }
});

it('la sucursal de la cotización son los almacenes, no una lista de ciudades', function () {
    $attribute = app(AttributeRepository::class)
        ->findWhere(['entity_type' => 'quotes', 'code' => 'sucursal'])
        ->first();

    expect($attribute->type)->toBe('lookup')
        ->and($attribute->lookup_type)->toBe('warehouses')
        ->and($attribute->options)->toHaveCount(0);

    // Y el lookup resuelve contra los almacenes cargados.
    $almacen = DB::table('warehouses')->first();

    expect(app(AttributeRepository::class)->getLookUpEntity('warehouses', $almacen->id)->name)->toBe($almacen->name);
});

it('el formulario pide la sucursal con un select simple de las plantas', function () {
    test()->actingAs(getDefaultAdmin());

    $planta = DB::table('warehouses')->orderBy('id')->first();

    $quote = cotizacionCiudad([
        'person_id' => personaCiudad()->id,
        'sucursal'  => $planta->id,
    ]);

    foreach ([route('admin.quotes.create'), route('admin.quotes.edit', $quote->id)] as $url) {
        $html = test()->get($url)->assertOk()->getContent();

        // Un <select name="sucursal">, no el buscador con autocompletar.
        expect($html)->toContain('<select')
            ->and($html)->toContain('name="sucursal"')
            ->and($html)->toContain('Sin asignar');

        // Una opción por planta cargada.
        foreach (DB::table('warehouses')->pluck('name') as $nombre) {
            expect($html)->toContain($nombre);
        }
    }

    // Al editar, la planta guardada viene elegida.
    $html = test()->get(route('admin.quotes.edit', $quote->id))->assertOk()->getContent();

    $select = substr($html, strpos($html, 'name="sucursal"'));
    $select = substr($select, 0, strpos($select, '</select>'));

    expect($select)->toContain('value="'.$planta->id.'" selected');
});

it('la cotización hereda la ciudad del contacto', function () {
    $persona = personaCiudad('Cochabamba');

    $quote = cotizacionCiudad(['person_id' => $persona->id]);

    expect(ciudadDe('quotes', $quote->id))->toBe('Cochabamba');
});

it('el contacto aprende la ciudad que trae la cotización', function () {
    $persona = personaCiudad();

    $quote = cotizacionCiudad([
        'person_id' => $persona->id,
        'ciudad'    => opcionCiudad('quotes', 'Tarija'),
    ]);

    expect(ciudadDe('persons', $persona->id))->toBe('Tarija')
        ->and(ciudadDe('quotes', $quote->id))->toBe('Tarija');
});

it('dos ciudades distintas no se pisan entre sí', function () {
    /*
     * Misma regla que el NIT: pisar la del contacto propagaría el error de una
     * cotización a todas las futuras, y pisar la de la cotización cambiaría un
     * documento ya emitido.
     */
    $persona = personaCiudad('La Paz');

    $quote = cotizacionCiudad([
        'person_id' => $persona->id,
        'ciudad'    => opcionCiudad('quotes', 'Oruro'),
    ]);

    expect(ciudadDe('persons', $persona->id))->toBe('La Paz')
        ->and(ciudadDe('quotes', $quote->id))->toBe('Oruro');
});

it('la oportunidad también se concilia con su contacto, en los dos sentidos', function () {
    $persona = personaCiudad('Sucre');

    $lead = oportunidadCiudad(['person' => ['id' => $persona->id], 'person_id' => $persona->id]);

    expect(ciudadDe('leads', $lead->id))->toBe('Sucre');

    // Y al revés: un contacto sin ciudad la aprende de su oportunidad.
    $otra = personaCiudad();

    $lead2 = oportunidadCiudad([
        'person'    => ['id' => $otra->id],
        'person_id' => $otra->id,
        'ciudad'    => opcionCiudad('leads', 'Trinidad'),
    ]);

    expect(ciudadDe('persons', $otra->id))->toBe('Trinidad')
        ->and(ciudadDe('leads', $lead2->id))->toBe('Trinidad');
});

it('la sucursal se propone desde la planta del producto, y no se pisa si ya vino', function () {
    $tapas = Product::where('sku', 'CM_00004')->first();   // el único con planta

    $quote = cotizacionCiudad([
        'person_id' => personaCiudad()->id,
        'items'     => [[
            'product_id' => $tapas->id, 'sku' => $tapas->sku, 'name' => $tapas->name,
            'quantity' => 1, 'price' => 1, 'total' => 1,
        ]],
    ]);

    expect(sucursalDe($quote->id))->toBe($tapas->warehouse_id);

    // Con sucursal elegida a mano, se respeta: puede haberse acordado otra planta.
    $otra = DB::table('warehouses')->where('id', '!=', $tapas->warehouse_id)->value('id');

    $quote2 = cotizacionCiudad([
        'person_id' => personaCiudad()->id,
        'sucursal'  => $otra,
        'items'     => [[
            'product_id' => $tapas->id, 'sku' => $tapas->sku, 'name' => $tapas->name,
            'quantity' => 1, 'price' => 1, 'total' => 1,
        ]],
    ]);

    expect(sucursalDe($quote2->id))->toBe($otra);

    foreach ([$quote->id, $quote2->id] as $id) {
        DB::table('quote_items')->where('quote_id', $id)->delete();
    }
});

it('la cotización del agente guarda ciudad y la planta del producto', function () {
    actingAsSanctumAuthenticatedAdmin();

    $persona = personaCiudad();

    $conv = Conversation::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+5917'.random_int(1000000, 9999999),
        'wa_name' => 'Ciudad', 'ai_enabled' => true, 'status' => 'open', 'person_id' => $persona->id,
    ]);

    // Tapas: es el producto que tiene planta asignada.
    $tapas = Product::where('sku', 'CM_00004')->first();

    $r = test()->postJson("/api/v1/productos/conversations/{$conv->id}/cotizacion", [
        'sku'       => 'CM_00004',
        'criterios' => ['Impresión' => 'Con impresión'],
        'cantidad'  => 30,
        'ciudad'    => 'la paz',   // como lo escribe el agente: sin acentos ni mayúsculas
    ])->assertStatus(201);

    $quoteId = $r->json('quote_id');

    expect(ciudadDe('quotes', $quoteId))->toBe('La Paz')
        ->and(ciudadDe('persons', $persona->id))->toBe('La Paz');

    $sucursal = app(AttributeRepository::class)
        ->findWhere(['entity_type' => 'quotes', 'code' => 'sucursal'])->first();

    expect(DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $quoteId)
        ->where('attribute_id', $sucursal->id)->value('integer_value'))->toBe($tapas->warehouse_id);

    DB::table('quote_items')->where('quote_id', $quoteId)->delete();
    DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $quoteId)->delete();
    DB::table('quotes')->where('id', $quoteId)->delete();
});
