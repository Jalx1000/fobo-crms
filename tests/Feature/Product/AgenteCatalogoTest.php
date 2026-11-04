<?php

use Webkul\Product\Models\ProductMedia;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Whatsapp\Models\Conversation;

/**
 * La API que consume el agente de IA.
 *
 * Lo que se protege acá es el contrato con n8n: qué ve el agente, qué NO puede
 * ver, y qué le pasa cuando pide algo que no existe.
 */
beforeEach(function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Caja agente '.uniqid(),
        'sku'         => 'TEST-AG-'.uniqid(),
        'price'       => 99,
    ]);
});

afterEach(function () {
    if (! isset($this->producto)) {
        return;
    }

    ProductMedia::where('product_id', $this->producto->id)->delete();

    // También la carpeta: borrar los archivos uno a uno deja el directorio
    // vacío, y con cada corrida de la suite se acumula uno nuevo.
    Illuminate\Support\Facades\Storage::disk('local')
        ->deleteDirectory("productos/{$this->producto->id}");

    $this->producto->newQuery()->whereKey($this->producto->id)->delete();
});

function adjunto(int $productId, string $tipo, array $extra = [])
{
    return app(ProductMediaRepository::class)->create(array_merge([
        'product_id' => $productId,
        'tipo'       => $tipo,
        'path'       => $tipo === 'enlace' ? null : "productos/{$productId}/".uniqid().'.jpg',
        'url'        => $tipo === 'enlace' ? 'https://ejemplo.com/'.uniqid() : null,
    ], $extra));
}

it('el catálogo esconde los productos sin adjuntos', function () {
    // Si el agente ve un producto sin material, le va a prometer fotos al
    // cliente que no existen.
    $r = test()->getJson('/api/v1/productos/catalogo?q='.$this->producto->sku)->assertOk();

    expect(collect($r->json('data'))->pluck('sku'))->not->toContain($this->producto->sku);

    adjunto($this->producto->id, 'imagen');

    $r = test()->getJson('/api/v1/productos/catalogo?q='.$this->producto->sku)->assertOk();

    expect(collect($r->json('data'))->pluck('sku'))->toContain($this->producto->sku);
});

it('el catálogo da CONTEOS de adjuntos, nunca rutas de archivo', function () {
    /*
     * La regla que sostiene el diseño: las rutas de los archivos no viajan por
     * el modelo de lenguaje. El agente sabe que hay 2 imágenes para decidir
     * cuántas pedir; dónde están es asunto del CRM.
     */
    adjunto($this->producto->id, 'imagen');
    adjunto($this->producto->id, 'imagen');
    adjunto($this->producto->id, 'documento');

    $r = test()->getJson('/api/v1/productos/catalogo?q='.$this->producto->sku)->assertOk();

    $fila = collect($r->json('data'))->firstWhere('sku', $this->producto->sku);

    expect($fila['adjuntos'])->toBe(['imagen' => 2, 'documento' => 1, 'enlace' => 0]);

    // Ni rutas, ni ids de media, ni URLs firmadas.
    expect(json_encode($fila))->not->toContain('productos/')
        ->and(json_encode($fila))->not->toContain('path')
        ->and(json_encode($fila))->not->toContain('/archivo');
});

it('la ficha devuelve las características con su etiqueta legible', function () {
    config()->set('caracteristicas.comunes', ['gramaje' => ['label' => 'Gramaje']]);

    adjunto($this->producto->id, 'imagen');

    $this->producto->update(['caracteristicas' => ['gramaje' => '300 g/m²']]);

    $r = test()->getJson('/api/v1/productos/catalogo/'.$this->producto->sku)->assertOk();

    expect($r->json('caracteristicas'))->toBe(['Gramaje' => '300 g/m²']);
});

it('caracteristicas es SIEMPRE un objeto JSON, nunca una lista', function () {
    /*
     * Un array PHP vacío se serializa como [] y uno con claves como {}. Sin el
     * cast, el agente tendría que manejar dos tipos para el mismo campo - y el
     * caso vacío es el más frecuente, porque casi ningún producto tiene
     * características cargadas todavía.
     *
     * Se afirma sobre el cuerpo crudo: ->json() decodifica {} a [] en PHP y
     * taparía justamente lo que se quiere comprobar.
     */
    adjunto($this->producto->id, 'imagen');

    $crudo = test()->getJson('/api/v1/productos/catalogo/'.$this->producto->sku)
        ->assertOk()->getContent();

    expect($crudo)->toContain('"caracteristicas":{}');
});

it('un SKU inexistente devuelve 404 y no una lista vacía', function () {
    test()->getJson('/api/v1/productos/catalogo/NO-EXISTE-XYZ')
        ->assertStatus(404)
        ->assertJsonPath('message', 'No existe un producto con el SKU NO-EXISTE-XYZ.');
});

it('pedir material de un producto sin ese tipo devuelve 404, no 200 con cero', function () {
    /*
     * El agente ya le dijo al cliente «te mando las fotos». Un 200 con
     * enviados=0 lo dejaría creyendo que salieron.
     */
    adjunto($this->producto->id, 'documento');

    $conversacion = conversacionConIa();

    test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'  => $this->producto->sku,
        'tipo' => 'imagen',
    ])->assertStatus(404);

    $conversacion->delete();
});

it('rechaza el envío si la conversación fue derivada a un asesor', function () {
    /*
     * La respuesta del agente viaja lejos -CRM → webhook → n8n piensa → POST de
     * vuelta- y en ese hueco puede haber una derivación. La guarda corre ANTES
     * de buscar el SKU: no tiene sentido resolver el catálogo para una
     * conversación a la que ya no se puede escribir.
     */
    adjunto($this->producto->id, 'imagen');

    $conversacion = conversacionConIa(['ai_enabled' => false]);

    test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'  => $this->producto->sku,
        'tipo' => 'imagen',
    ])->assertStatus(409);

    $conversacion->delete();
});

it('exige sku y tipo válidos', function () {
    test()->postJson('/api/v1/productos/conversations/1/media', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sku', 'tipo']);

    test()->postJson('/api/v1/productos/conversations/1/media', [
        'sku'  => $this->producto->sku,
        'tipo' => 'video',
    ])->assertStatus(422)->assertJsonValidationErrors('tipo');
});

it('la API del agente exige token', function () {
    // Sin sanctum: el catálogo no se sirve a cualquiera.
    app('auth')->forgetGuards();

    test()->getJson('/api/v1/productos/catalogo')->assertStatus(401);
});

/**
 * Una conversación en la que el agente sí puede escribir.
 *
 * `last_inbound_at` reciente para que la ventana de 24 h esté abierta: sin eso
 * el envío se rechaza con 422 antes de llegar a lo que se quiere probar.
 */
function conversacionConIa(array $extra = []): Conversation
{
    return Conversation::create(array_merge([
        'wa_id'           => '591'.random_int(60000000, 79999999),
        'name'            => 'Prueba agente',
        'ai_enabled'      => true,
        'handoff_state'   => null,
        'last_inbound_at' => now(),
        'last_message_at' => now(),
    ], $extra));
}

it('envía las imágenes en orden, con la portada primero y como sender=ia', function () {
    Illuminate\Support\Facades\Bus::fake();

    $disco = Illuminate\Support\Facades\Storage::disk('local');

    // Tres imágenes reales en el disco: el envío lee los bytes de verdad.
    $paths = [];

    foreach ([1, 2, 3] as $i) {
        $path = "productos/{$this->producto->id}/f{$i}.jpg";

        // El objeto se sostiene en una variable: el temp de UploadedFile::fake()
        // se borra en cuanto se pierde la referencia, y getPathname() apuntaría
        // a un archivo que ya no está.
        $falso = Illuminate\Http\UploadedFile::fake()->image("f{$i}.jpg");

        $disco->put($path, file_get_contents($falso->getPathname()));

        $paths[] = $path;
    }

    adjunto($this->producto->id, 'imagen', ['path' => $paths[0], 'orden' => 1]);
    adjunto($this->producto->id, 'imagen', ['path' => $paths[1], 'orden' => 2]);
    $portada = adjunto($this->producto->id, 'imagen', ['path' => $paths[2], 'orden' => 3, 'es_portada' => true]);

    $conversacion = conversacionConIa();

    test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'      => $this->producto->sku,
        'tipo'     => 'imagen',
        'cantidad' => 3,
    ])->assertOk()->assertJsonPath('enviados', 3)->assertJsonPath('sku', $this->producto->sku);

    $mensajes = Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)
        ->orderBy('id')->get();

    expect($mensajes)->toHaveCount(3)
        // sender=ia y no 'human': el hilo tiene que mostrar que lo mandó el agente.
        ->and($mensajes->pluck('sender')->unique()->all())->toBe(['ia'])
        ->and($mensajes->pluck('type')->unique()->all())->toBe(['image'])
        // Sin caption: el agente manda su propia descripción como texto aparte.
        ->and($mensajes->pluck('body')->filter()->all())->toBe([]);

    // La portada primero: es lo primero que el cliente ve.
    expect($mensajes->first()->payload['media_disk_path'])->not->toBeNull();

    $mensajes->each->delete();
    $conversacion->delete();
    collect($paths)->each(fn ($p) => $disco->delete($p));
});

it('el techo de cantidad no se puede superar aunque el agente pida más', function () {
    // cantidad_max protege el chat del cliente; no es una sugerencia.
    config()->set('adjuntos.envio.cantidad_max', 2);

    $disco = Illuminate\Support\Facades\Storage::disk('local');

    foreach ([1, 2, 3, 4] as $i) {
        $path = "productos/{$this->producto->id}/t{$i}.jpg";

        $falso = Illuminate\Http\UploadedFile::fake()->image("t{$i}.jpg");

        $disco->put($path, file_get_contents($falso->getPathname()));

        adjunto($this->producto->id, 'imagen', ['path' => $path, 'orden' => $i]);
    }

    Illuminate\Support\Facades\Bus::fake();

    $conversacion = conversacionConIa();

    test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'      => $this->producto->sku,
        'tipo'     => 'imagen',
        'cantidad' => 99,
    ])->assertOk()->assertJsonPath('enviados', 2);

    Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->delete();
    $conversacion->delete();
    ProductMedia::where('product_id', $this->producto->id)->get()
        ->each(fn ($m) => $disco->delete($m->path));
});

it('un enlace se manda como texto, no como archivo', function () {
    Illuminate\Support\Facades\Bus::fake();

    adjunto($this->producto->id, 'enlace', ['url' => 'https://imprimir.example/ficha-caja']);

    $conversacion = conversacionConIa();

    test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'  => $this->producto->sku,
        'tipo' => 'enlace',
    ])->assertOk()->assertJsonPath('enviados', 1);

    $mensaje = Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->first();

    // Texto y no document: es lo único que hace que WhatsApp arme el preview.
    expect($mensaje->type)->toBe('text')
        ->and($mensaje->body)->toBe('https://imprimir.example/ficha-caja');

    $mensaje->delete();
    $conversacion->delete();
});

/*
|--------------------------------------------------------------------------
| Adjuntos por variante: la ficha de 50 L es de 50 L, no de «Magia Verde»
|--------------------------------------------------------------------------
*/

function varianteDe($producto, string $tamano)
{
    return Webkul\Product\Models\ProductVariante::create([
        'product_id' => $producto->id,
        'valores'    => ['Tamaño' => $tamano],
        'unidad'     => 'PZA',
        'orden'      => 0,
    ]);
}

function conFichas($test)
{
    $test->producto->forceFill(['ejes_precio' => ['Tamaño'], 'ejes_identidad' => ['Tamaño']])->save();

    $disco = Illuminate\Support\Facades\Storage::disk('local');

    $pdf = fn (string $n) => tap("productos/{$test->producto->id}/{$n}.pdf", fn ($p) => $disco->put($p, '%PDF-1.4 fake'));

    $v50 = varianteDe($test->producto, '50 L');
    $v140 = varianteDe($test->producto, '140 L');

    return [
        'v50'     => $v50,
        'v140'    => $v140,
        'ficha50' => adjunto($test->producto->id, 'documento', ['path' => $pdf('ficha-50'), 'orden' => 1, 'variante_id' => $v50->id]),
        'ficha140' => adjunto($test->producto->id, 'documento', ['path' => $pdf('ficha-140'), 'orden' => 2, 'variante_id' => $v140->id]),
        'general' => adjunto($test->producto->id, 'documento', ['path' => $pdf('catalogo'), 'orden' => 3]),
    ];
}

it('con criterios manda solo la ficha de esa variante', function () {
    Illuminate\Support\Facades\Bus::fake();

    $f = conFichas($this);
    $conversacion = conversacionConIa();

    $r = test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'       => $this->producto->sku,
        'tipo'      => 'documento',
        'criterios' => ['Tamaño' => '50 L'],
    ])->assertOk();

    expect($r->json('enviados'))->toBe(1)
        ->and($r->json('variante'))->toBe('50 L')
        ->and($r->json('alcance'))->toBe('variante');

    $msg = Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->first();

    // El envío copia el archivo a whatsapp/media con otro nombre; lo que
    // identifica cuál salió es el nombre original.
    expect($msg->media_name)->toBe('ficha-50.pdf');

    Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->delete();
    $conversacion->delete();
    Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)->delete();
});

it('si la variante no tiene adjuntos propios caen los generales, nunca los de otra variante', function () {
    Illuminate\Support\Facades\Bus::fake();

    $f = conFichas($this);
    $v75 = varianteDe($this->producto, '75 L');   // sin ficha propia
    $conversacion = conversacionConIa();

    $r = test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'       => $this->producto->sku,
        'tipo'      => 'documento',
        'criterios' => ['Tamaño' => '75 L'],
        'cantidad'  => 5,
    ])->assertOk();

    expect($r->json('enviados'))->toBe(1)
        ->and($r->json('alcance'))->toBe('producto');

    $nombres = Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->pluck('media_name')->all();

    // Solo el catálogo general: la ficha de 50 L o la de 140 L sería la equivocada.
    expect($nombres)->toBe(['catalogo.pdf']);

    Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->delete();
    $conversacion->delete();
    Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)->delete();
});

it('sin criterios, si hay fichas por variante, pide la variante en vez de mandar todas', function () {
    /*
     * Pasó el 21/09: el cliente pidió la de 50 L y le llegaron 35 L, 200 L y
     * 50 L, en orden de carga. «Mandar todo» deja de ser una opción cuando
     * el producto tiene una ficha por variante.
     */
    Illuminate\Support\Facades\Bus::fake();

    conFichas($this);
    $conversacion = conversacionConIa();

    $r = test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'      => $this->producto->sku,
        'tipo'     => 'documento',
        'cantidad' => 5,
    ])->assertStatus(422);

    expect($r->json('variantes'))->toBe(['50 L', '140 L'])
        ->and(Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->count())->toBe(0);

    // Las imágenes generales siguen saliendo sin criterios: no tienen versión por variante.
    // El UploadedFile se sostiene en una variable: su temporal se borra en
    // cuanto se pierde la referencia (mismo detalle que en el test de orden).
    $falso = Illuminate\Http\UploadedFile::fake()->image('f.jpg');
    $foto = "productos/{$this->producto->id}/foto.jpg";
    Illuminate\Support\Facades\Storage::disk('local')->put($foto, file_get_contents($falso->getPathname()));
    adjunto($this->producto->id, 'imagen', ['path' => $foto]);

    test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'  => $this->producto->sku,
        'tipo' => 'imagen',
    ])->assertOk()->assertJsonPath('enviados', 1);

    Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->delete();
    $conversacion->delete();
    Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)->delete();
});

it('criterios que no identifican una variante devuelven 422, no todas las fichas', function () {
    Illuminate\Support\Facades\Bus::fake();

    conFichas($this);
    $conversacion = conversacionConIa();

    $r = test()->postJson("/api/v1/productos/conversations/{$conversacion->id}/media", [
        'sku'       => $this->producto->sku,
        'tipo'      => 'documento',
        'criterios' => ['Tamaño' => '50 litros'],
    ])->assertStatus(422);

    expect($r->json('message'))->toContain('no identifican una variante')
        ->and($r->json('variantes'))->toBe(['50 L', '140 L'])
        ->and(Webkul\Whatsapp\Models\Message::where('conversation_id', $conversacion->id)->count())->toBe(0);

    $conversacion->delete();
    Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)->delete();
});

it('desde la ficha del producto se asigna cada adjunto a su variante', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    $this->producto->forceFill(['ejes_precio' => ['Tamaño'], 'ejes_identidad' => ['Tamaño']])->save();
    $v50 = varianteDe($this->producto, '50 L');
    $doc = adjunto($this->producto->id, 'documento');

    // La galería ofrece el selector con la variante.
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();
    expect($html)->toContain('data-variante-de="'.$doc->id.'"')->and($html)->toContain('>50 L<');

    test()->patchJson(route('admin.products.medias.variante', ['id' => $this->producto->id, 'media' => $doc->id]), [
        'variante_id' => $v50->id,
    ])->assertOk()->assertJsonPath('variante_id', $v50->id);

    expect($doc->fresh()->variante_id)->toBe($v50->id);

    // Volver a general.
    test()->patchJson(route('admin.products.medias.variante', ['id' => $this->producto->id, 'media' => $doc->id]), [
        'variante_id' => null,
    ])->assertOk();

    expect($doc->fresh()->variante_id)->toBeNull();

    // Una variante de OTRO producto no se acepta.
    $ajena = Webkul\Product\Models\ProductVariante::where('product_id', '!=', $this->producto->id)->first();

    if ($ajena) {
        test()->patchJson(route('admin.products.medias.variante', ['id' => $this->producto->id, 'media' => $doc->id]), [
            'variante_id' => $ajena->id,
        ])->assertStatus(422);
    }

    Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)->delete();
});

it('guardar el producto no desvincula las fichas de sus variantes', function () {
    /*
     * 21/09: se asignaron las cinco fichas de Magia Verde y un minuto después
     * alguien guardó el producto. Guardar borraba y reinsertaba las variantes
     * con ids nuevos, la FK (nullOnDelete) dejó las fichas sin variante, y al
     * cliente que pidió 50 L le llegaron tres PDFs.
     */
    test()->actingAs(getDefaultAdmin(), 'user');

    $f = conFichas($this);
    $idAntes = $f['v50']->id;

    // Guardar el formulario con las mismas variantes (y una nueva).
    test()->put(route('admin.products.update', $this->producto->id), [
        'entity_type'        => 'products',
        'name'               => $this->producto->name,
        'sku'                => $this->producto->sku,
        'price'              => 0,
        'quantity'           => 0,
        'precios_enviados'   => 1,
        'caracteristicas_enviadas' => 1,
        'variantes_enviadas' => 1,
        'ejes_precio'        => ['Tamaño'],
        'ejes_identidad'     => ['Tamaño'],
        'variantes'          => [
            ['valores' => ['Tamaño' => '50 L'],  'unidad' => 'PZA', 'medida' => '(65×80) cm'],
            ['valores' => ['Tamaño' => '140 L'], 'unidad' => 'PZA'],
            ['valores' => ['Tamaño' => '200 L'], 'unidad' => 'PZA'],
        ],
    ])->assertRedirect();

    $v50 = Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)
        ->get()->first(fn ($v) => ($v->valores['Tamaño'] ?? null) === '50 L');

    // Misma variante (mismo id), con el dato nuevo aplicado, y la ficha sigue suya.
    expect($v50->id)->toBe($idAntes)
        ->and($v50->medida)->toBe('(65×80) cm')
        ->and($f['ficha50']->fresh()->variante_id)->toBe($idAntes)
        ->and(Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)->count())->toBe(3);

    Webkul\Product\Models\ProductVariante::where('product_id', $this->producto->id)->delete();
});
