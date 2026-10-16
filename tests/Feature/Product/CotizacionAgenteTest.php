<?php

use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\Whatsapp\Models\Conversation;

/**
 * La cotización que el agente deja pre-elaborada al cerrar la toma de interés.
 *
 * Antes el PASO 5 solo decía «hemos registrado tu información» y lo único que
 * quedaba era la conversación: el asesor tenía que leerla entera para
 * reconstruir qué quería el cliente. Lo que se protege acá es que el precio y el
 * total los ponga el CRM, no el modelo.
 */
beforeEach(function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->persona = app(PersonRepository::class)->create([
        'entity_type' => 'persons',
        'name'        => 'Cotiza '.uniqid(),
    ]);

    $this->conv = Conversation::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '59177001'.rand(100, 999),
        'wa_name'    => 'Cotiza',
        'ai_enabled' => true,
        'person_id'  => $this->persona->id,
    ]);

    $this->quotes = [];
});

afterEach(function () {
    foreach ($this->leadsCreados ?? [] as $lead) {
        DB::table('lead_quotes')->where('lead_id', $lead)->delete();
        DB::table('leads')->where('id', $lead)->delete();
    }

    foreach ($this->quotes ?? [] as $id) {
        DB::table('quote_items')->where('quote_id', $id)->delete();
        DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $id)->delete();
        DB::table('quotes')->where('id', $id)->delete();
    }

    if (isset($this->conv)) {
        Conversation::whereKey($this->conv->id)->delete();
    }

    if (isset($this->persona)) {
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $this->persona->id)->delete();
        DB::table('persons')->where('id', $this->persona->id)->delete();
    }
});

it('crea la cotización con el precio que pone el CRM', function () {
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 25,
        'empresa'   => 'Restaurante El Fogón',
        'nit'       => '1234567019',
    ])->assertStatus(201);

    $this->quotes[] = $r->json('quote_id');

    expect((float) $r->json('precio'))->toBe(13.0);
    expect((float) $r->json('total'))->toBe(325.0);

    $quote = app(QuoteRepository::class)->find($r->json('quote_id'));

    expect($quote->subject)->toContain('Bolsas Magia Verde');
    expect($quote->person_id)->toBe($this->persona->id);
    expect((float) $quote->grand_total)->toBe(325.0);
    expect($quote->nit)->toBe('1234567019');

    // El ítem lleva la cantidad y el unitario, no solo el total.
    $item = DB::table('quote_items')->where('quote_id', $quote->id)->first();

    expect($item->sku)->toBe('CM_00002');
    expect((int) $item->quantity)->toBe(25);
    expect((float) $item->price)->toBe(13.0);
});

it('el NIT llega a la ficha del contacto por el listener', function () {
    // Es el mismo camino que cuando lo carga un asesor: el endpoint dispara
    // quote.create.after, que es donde vive la sincronización.
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00004',
        'criterios' => ['Impresión' => 'Sin impresión'],
        'cantidad'  => 3,
        'nit'       => '5556667044',
    ])->assertStatus(201);

    $this->quotes[] = $r->json('quote_id');

    expect(app(PersonRepository::class)->find($this->persona->id)->nit)->toBe('5556667044');
});

it('no cotiza cuando faltan ejes: dice cuáles', function () {
    // Una cotización formal con el precio de «la más parecida» es peor que no
    // tener cotización: nadie la revisa sabiendo que hay que dudar de ella.
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L'],
        'cantidad'  => 25,
    ])->assertStatus(422);

    expect($r->json('message'))->toContain('Faltan datos');
    expect($r->json('message'))->toContain('Canal');
});

/*
| Sin precio cargado la cotización se crea igual, con precio pendiente.
|
| Caso real (08/10–12/10): 9 de 21 cotizaciones del agente rebotaban con 422
| porque el Stretch Film no tiene precio. El cliente había dado todos sus datos
| y no quedaba nada para el asesor. Ahora queda en 0, marcada como pendiente,
| y no se puede enviar hasta que el asesor ponga el precio.
*/
it('una combinación que no se cotiza por chat se crea con precio pendiente y la nota del catálogo', function () {
    Mail::fake();
    Queue::fake();

    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'DISTRIBUIDOR', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 100,
    ])->assertStatus(201)->assertJsonPath('precio_pendiente', true);

    $this->quotes[] = $r->json('quote_id');

    $quote = app(QuoteRepository::class)->find($r->json('quote_id'));

    expect((float) $quote->grand_total)->toBe(0.0)
        ->and($quote->description)->toContain('PRECIO PENDIENTE')
        ->and($quote->description)->toContain('distribuidores')
        ->and((float) $r->json('total'))->toBe(0.0);
});

it('una combinación sin precio cargado se cotiza con precio pendiente', function () {
    // El Stretch Film ya tiene precio; Bolsas HOGAR sigue sin precio en la lista.
    Mail::fake();
    Queue::fake();

    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'HOGAR', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 4,
        'empresa'   => 'Union Agronegocios',
    ])->assertStatus(201)->assertJsonPath('precio_pendiente', true);

    $this->quotes[] = $r->json('quote_id');

    $item = DB::table('quote_items')->where('quote_id', $r->json('quote_id'))->first();

    expect((int) $item->quantity)->toBe(4)
        ->and((float) $item->price)->toBe(0.0);
});

it('una combinación que no está en la lista también queda, con lo que pidió el cliente', function () {
    Mail::fake();
    Queue::fake();

    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '999 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 25,
    ])->assertStatus(201)->assertJsonPath('precio_pendiente', true);

    $this->quotes[] = $r->json('quote_id');

    expect(app(QuoteRepository::class)->find($r->json('quote_id'))->description)
        ->toContain('PRECIO PENDIENTE')
        ->toContain('999 L');
});

it('con precio cargado no queda pendiente', function () {
    Mail::fake();
    Queue::fake();

    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 25,
    ])->assertStatus(201)->assertJsonPath('precio_pendiente', false);

    $this->quotes[] = $r->json('quote_id');
});

it('no crea una cotización huérfana sin contacto', function () {
    $sinPersona = Conversation::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '59177009'.rand(100, 999),
        'ai_enabled' => true,
    ]);

    test()->postJson("/api/v1/productos/conversations/{$sinPersona->id}/cotizacion", [
        'sku'       => 'CM_00004',
        'criterios' => ['Impresión' => 'Sin impresión'],
        'cantidad'  => 1,
    ])->assertStatus(422);

    Conversation::whereKey($sinPersona->id)->delete();
});

it('la descripción le deja al asesor lo que no entra en ningún campo', function () {
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00004',
        'criterios' => ['Impresión' => 'Con impresión'],
        'cantidad'  => 30,
        'empresa'   => 'Envases del Sur SRL',
        'notas'     => 'Quiere el logo en dos colores.',
    ])->assertStatus(201);

    $this->quotes[] = $r->json('quote_id');

    $d = app(QuoteRepository::class)->find($r->json('quote_id'))->description;

    expect($d)->toContain('Envases del Sur SRL')
        ->and($d)->toContain('Cantidad: 30')
        ->and($d)->toContain('30 cajas')          // el mínimo, que es lo que más se olvida
        ->and($d)->toContain('dos colores');
});

it('nunca deja que MySQL corte la descripción en silencio', function () {
    /*
     * `quotes.description` es varchar(255) y la conexión NO está en modo
     * estricto: lo que pasa de ahí lo corta la base sin error. Se detectó
     * perdiendo las notas del cliente —lo único que no se puede reconstruir— al
     * final de una descripción de 259 caracteres.
     */
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00004',
        'criterios' => ['Impresión' => 'Con impresión'],
        'cantidad'  => 30,
        'empresa'   => 'Una razón social larguísima de las que se usan de verdad SRL',
        'notas'     => str_repeat('detalle del pedido que el cliente dictó por chat, ', 8),
    ])->assertStatus(201);

    $this->quotes[] = $r->json('quote_id');

    $d = app(QuoteRepository::class)->find($r->json('quote_id'))->description;

    expect(mb_strlen($d))->toBeLessThanOrEqual(255);

    // Y lo que se recortó se nota: sin la marca, el asesor lee una frase que
    // termina bien y no sabe que le falta la mitad.
    expect($d)->toContain('…');

    // Lo caro se conserva: la cantidad y el mínimo van antes que la nota fija
    // del catálogo, que se puede releer del producto.
    expect($d)->toContain('Cantidad: 30')->and($d)->toContain('Mínimo: 30 cajas');
});

it('el asunto nombra la variante en el orden de los ejes', function () {
    // MySQL normaliza el orden de las claves de una columna JSON: sin
    // reordenar, el asunto salía «Santa Cruz · HORECA · 50 L» en vez de
    // «50 L · HORECA · Santa Cruz», que es como lo nombra el vendedor.
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 25,
    ])->assertStatus(201);

    $this->quotes[] = $r->json('quote_id');

    expect($r->json('asunto'))->toBe('Bolsas Magia Verde — 50 L · HORECA · Santa Cruz');
});

it('la cotización del agente sale con los mismos códigos y condiciones que la de pantalla', function () {
    // Criterio de éxito 5 del spec: las dos vías tienen que producir lo mismo.
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 25,
        'empresa'   => 'GRUPO QUIROZ HOLDING',
        'nit'       => '1234567019',
    ])->assertStatus(201);

    $this->quotes[] = $id = $r->json('quote_id');

    $quote = app(QuoteRepository::class)->find($id);

    expect($quote->razon_social)->toBe('GRUPO QUIROZ HOLDING');
    expect($quote->tiempo_entrega)->toBe('Inmediata');
    expect(\Webkul\Product\Support\Opciones::nombre($quote->lugar_entrega))->toBe('Puesto almacén cliente');
    expect(\Webkul\Product\Support\Opciones::nombre($quote->forma_pago))->toBe('Al contado');

    // Los códigos de la variante, sin que el agente los mande.
    $item = DB::table('quote_items')->where('quote_id', $id)->first();

    expect($item->codigo_tr4)->toBe('020117-0035');
    expect($item->codigo_barra)->toBe('7779970880037');
    expect($item->medida)->toBe('(65×80) cm');
    expect($item->unidad)->toBe('PZA');

    expect($r->json('entrega'))->toBe(['tiempo' => 'Inmediata', 'lugar' => 'Puesto almacén cliente']);
});

it('un pedido de tapas impresas se cotiza con sus 20 días', function () {
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00004',
        'criterios' => ['Impresión' => 'Con impresión'],
        'cantidad'  => 30,
    ])->assertStatus(201);

    $this->quotes[] = $id = $r->json('quote_id');

    $quote = app(QuoteRepository::class)->find($id);

    expect($quote->tiempo_entrega)->toBe('20 días');
    expect(\Webkul\Product\Support\Opciones::nombre($quote->lugar_entrega))->toBe('Recoge en planta');
});

it('devuelve las líneas creadas, para que el agente pueda verificarlas', function () {
    /*
     * Reportado (14/09): «las cotizaciones se crean sin líneas». Las líneas SÍ
     * se creaban; lo que no se veía era el endpoint de lectura de Krayin, que
     * no incluye los ítems en su respuesta. Ahora vienen en el 201.
     */
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'TRADICIONAL', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 20,
    ])->assertStatus(201);

    $this->quotes[] = $r->json('quote_id');

    expect($r->json('items'))->toHaveCount(1);

    expect($r->json('items.0'))->toMatchArray([
        'sku'          => 'CM_00002',
        'codigo_tr4'   => '020117-0035',
        'codigo_barra' => '7779970880037',
        'medida'       => '(65×80) cm',
        'unidad'       => 'PZA',
        'cantidad'     => 20,
    ]);
});

it('acepta la marca por nombre y la guarda como opción', function () {
    // El agente manda «Magia Verde»; no tiene por qué conocer los ids del CRM.
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'       => 'CM_00002',
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'TRADICIONAL', 'Zona' => 'Santa Cruz'],
        'cantidad'  => 20,
        'marca'     => 'Magia Verde',
    ])->assertStatus(201);

    $this->quotes[] = $id = $r->json('quote_id');

    expect(\Webkul\Product\Support\Opciones::nombre(app(QuoteRepository::class)->find($id)->marca))
        ->toBe('Magia Verde');
});

it('tolera acentos y mayúsculas en la marca', function () {
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku'   => 'CM_00004', 'criterios' => ['Impresión' => 'Sin impresión'], 'cantidad' => 1,
        'marca' => 'imprimir',
    ])->assertStatus(201);

    $this->quotes[] = $id = $r->json('quote_id');

    expect(\Webkul\Product\Support\Opciones::nombre(app(QuoteRepository::class)->find($id)->marca))
        ->toBe('Imprimir');
});

it('la cotización se puede leer con sus líneas, y lo que no existe es 404', function () {
    /*
     * `GET /api/v1/quotes/{id}` de Krayin no devuelve los ítems y responde 500
     * cuando la cotización no existe (le pasa null al Resource). Este endpoint
     * es el que el agente debe usar.
     */
    $r = test()->postJson("/api/v1/productos/conversations/{$this->conv->id}/cotizacion", [
        'sku' => 'CM_00003', 'criterios' => ['Color' => 'Negro'], 'cantidad' => 2,
    ])->assertStatus(201);

    $this->quotes[] = $id = $r->json('quote_id');

    $leida = test()->getJson("/api/v1/productos/cotizaciones/{$id}")->assertOk();

    expect($leida->json('quote_id'))->toBe($id);
    expect($leida->json('items'))->toHaveCount(1);
    expect($leida->json('items.0.sku'))->toBe('CM_00003');
    expect($leida->json('items.0.cantidad'))->toBe(2);

    test()->getJson('/api/v1/productos/cotizaciones/99999999')->assertNotFound();
});

it('la cotización del agente queda vinculada a la oportunidad de la conversación', function () {
    /*
     * Antes se le pasaba `lead_id` al repositorio y se perdía en silencio:
     * `quotes` no tiene esa columna, la relación es la tabla `lead_quotes`. La
     * cotización quedaba suelta y no aparecía en la pestaña «Cotizaciones» del
     * pedido, así que el asesor veía la charla pero no el documento.
     */
    $lead = DB::table('leads')->insertGetId([
        'title'                  => 'Vínculo test '.uniqid(),
        'person_id'              => $this->persona->id,
        'user_id'                => 1,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->value('id'),
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    Conversation::whereKey($this->conv->id)->update(['lead_id' => $lead]);

    $r = test()->postJson('/api/v1/productos/conversations/'.$this->conv->id.'/cotizacion', [
        'sku' => 'CM_00002', 'criterios' => ['Tamaño' => '50 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz'], 'cantidad' => 10,
    ])->assertStatus(201);

    $this->quotes[] = $r->json('quote_id');

    expect(DB::table('lead_quotes')->where('lead_id', $lead)->where('quote_id', $r->json('quote_id'))->exists())->toBeTrue();

    DB::table('lead_quotes')->where('lead_id', $lead)->delete();
    DB::table('leads')->where('id', $lead)->delete();
});

/*
| Al crear la cotización, el pedido pasa a SOLICITUD RECIBIDA de SU pipeline
| (cada ciudad tiene el suyo). Solo desde NO ATENIDOS: un pedido que ya está
| más adelante no retrocede.
*/
function pedidoEnEtapa($test, int $pipeline, string $codigo): int
{
    $lead = DB::table('leads')->insertGetId([
        'title'                  => 'Etapa test '.uniqid(),
        'person_id'              => $test->persona->id,
        'user_id'                => 1,
        'lead_pipeline_id'       => $pipeline,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->where('code', $codigo)->value('id'),
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    Conversation::whereKey($test->conv->id)->update(['lead_id' => $lead]);

    return $lead;
}

function codigoEtapa(int $lead): string
{
    return DB::table('lead_pipeline_stages')->where('id', DB::table('leads')->where('id', $lead)->value('lead_pipeline_stage_id'))->value('code');
}

function cotizarYLimpiar($test, int $lead): void
{
    Mail::fake();
    Queue::fake();

    $r = test()->postJson('/api/v1/productos/conversations/'.$test->conv->id.'/cotizacion', [
        'sku' => 'CM_00002', 'criterios' => ['Tamaño' => '50 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz'], 'cantidad' => 10,
    ])->assertStatus(201);

    $test->quotes[] = $r->json('quote_id');
    $test->leadsCreados[] = $lead;
}

it('al crear la cotización el pedido pasa de NO ATENIDOS a SOLICITUD RECIBIDA', function () {
    $lead = pedidoEnEtapa($this, 1, 'no-atendidos');

    cotizarYLimpiar($this, $lead);

    expect(codigoEtapa($lead))->toBe('solicitud-recibida');
});

it('usa la SOLICITUD RECIBIDA del pipeline del pedido, no la de Santa Cruz', function () {
    $pipeline = (int) DB::table('lead_pipelines')->where('id', '!=', 1)->value('id');
    $lead = pedidoEnEtapa($this, $pipeline, 'no-atendidos');

    cotizarYLimpiar($this, $lead);

    $lead = DB::table('leads')->find($lead);

    expect((int) $lead->lead_pipeline_id)->toBe($pipeline)
        ->and(DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('lead_pipeline_id'))->toBe($pipeline)
        ->and(codigoEtapa($lead->id))->toBe('solicitud-recibida');
});

it('un pedido que ya está más adelante no retrocede', function () {
    $lead = pedidoEnEtapa($this, 1, 'cotizacion-enviada');

    cotizarYLimpiar($this, $lead);

    expect(codigoEtapa($lead))->toBe('cotizacion-enviada');
});
