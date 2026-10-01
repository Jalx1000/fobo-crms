<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\User\Models\User;

/**
 * El bloque de firmas al pie del PDF de cotización.
 *
 * Dos columnas: el asesor asignado (con su firma escaneada y su cargo) y el
 * cliente (línea para firmar, empresa y NIT). Lo que se protege acá es que se
 * lea el asesor DE LA COTIZACIÓN y no el usuario que imprime, y que el PDF no
 * se rompa cuando falta la firma.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->asesor = User::create([
        'name'            => 'Ana Vendedora '.uniqid(),
        'cargo'           => 'Ejecutiva comercial',
        'email'           => 'ana'.uniqid().'@test.local',
        'password'        => bcrypt('x'),
        'role_id'         => getDefaultAdmin()->role_id,
        'status'          => 1,
        'view_permission' => 'global',
    ]);

    // Un PNG mínimo válido (1x1) como firma.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $this->asesor->update(['firma' => 'admins/'.$this->asesor->id.'/firma/firma.png']);
    Storage::put($this->asesor->firma, $png);

    $this->persona = app(PersonRepository::class)->create([
        'entity_type' => 'persons',
        'name'        => 'Cliente Firmante '.uniqid(),
        'nit'         => '1234567019',
    ]);

    $this->cotizacion = app(QuoteRepository::class)->create([
        'entity_type' => 'quotes',
        'subject'     => 'PDF firmas '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => $this->asesor->id,
        'expired_at'  => now()->addDays(7)->toDateString(),
        'sub_total'   => 100,
        'grand_total' => 100,
        'items'       => [],
    ]);
});

afterEach(function () {
    if (isset($this->cotizacion)) {
        DB::table('quote_items')->where('quote_id', $this->cotizacion->id)->delete();
        DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $this->cotizacion->id)->delete();
        DB::table('quotes')->where('id', $this->cotizacion->id)->delete();
    }

    if (isset($this->persona)) {
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $this->persona->id)->delete();
        DB::table('persons')->where('id', $this->persona->id)->delete();
    }

    if (isset($this->asesor)) {
        Storage::deleteDirectory('admins/'.$this->asesor->id);
        DB::table('users')->where('id', $this->asesor->id)->delete();
    }
});

function htmlDelPdf($quote): string
{
    return view('admin::quotes.pdf', ['quote' => app(QuoteRepository::class)->find($quote->id)])->render();
}

it('muestra al asesor asignado con su firma y cargo, y al cliente con su NIT', function () {
    $html = htmlDelPdf($this->cotizacion);

    expect($html)->toContain('class="firmas"')
        ->and($html)->toContain($this->asesor->name)
        ->and($html)->toContain('Ejecutiva comercial')
        ->and($html)->toContain('Agente comercial')  // etiqueta del formato RG-VCO-005
        ->and($html)->toContain($this->persona->name)
        ->and($html)->toContain('NIT 1234567019')
        // El rol del cliente se muestra en mayúsculas por CSS, así que en el
        // HTML está como lo dice el idioma.
        ->and(substr($html, strpos($html, 'class="firmas"')))->toContain('Cliente');

    // La firma va embebida: dompdf no puede pedirle una URL al propio servidor.
    expect($html)->toContain('class="firma-img"')
        ->and($html)->toContain('data:image/png;base64,');
});

it('usa el asesor de la cotización, no el usuario que imprime', function () {
    // Quien imprime es el admin; el asesor asignado es Ana. Tiene que salir Ana.
    $html = htmlDelPdf($this->cotizacion);

    expect($html)->toContain($this->asesor->name);

    $admin = getDefaultAdmin();

    if ($admin->name !== $this->asesor->name) {
        /*
         * «Agente comercial» es la etiqueta fija del formato RG-VCO-005, y hay
         * un usuario que se llama «Agente»: buscar el nombre a secas daba un
         * falso positivo contra la etiqueta. Se quita la etiqueta antes de
         * comparar, que es lo que se quiere comprobar de verdad.
         */
        $bloque = str_replace(
            ['Agente comercial', 'Ejecutiva comercial', 'Ejecutivo comercial'],
            '',
            substr($html, strpos($html, 'class="firmas"'))
        );

        expect($bloque)->not->toContain($admin->name);
    }
});

it('sin firma cargada deja la línea para firmar a mano y no rompe', function () {
    Storage::delete($this->asesor->firma);
    $this->asesor->update(['firma' => null, 'cargo' => null]);

    $html = htmlDelPdf($this->cotizacion);

    expect($html)->toContain('class="firmas"')
        ->and($html)->not->toContain('class="firma-img"')
        ->and($html)->toContain($this->asesor->name);
});

it('el endpoint de impresión genera el PDF', function () {
    $r = test()->get(route('admin.quotes.print', $this->cotizacion->id));

    $r->assertOk();

    expect($r->headers->get('content-type'))->toContain('pdf');
});

it('el PDF reproduce el formato RG-VCO-005', function () {
    // Criterio de éxito 4 del spec. La verificación visual (rasterizar y
    // comparar) se hizo a mano; esto protege que no desaparezca nada.
    $html = htmlDelPdf($this->cotizacion);

    // Cabecera del sistema de calidad, desde config.
    foreach (['RG-VCO-005', 'PROPUESTA COMERCIAL MASIVOS', 'REGISTRO', '19/4/2024'] as $txt) {
        expect($html)->toContain($txt);
    }

    // Las nueve columnas del formato.
    foreach (['N°', 'Medida', 'COD TR4', 'Código de barra', 'Unid.', 'Cantidad', 'Precio', 'Monto'] as $col) {
        expect($html)->toContain($col);
    }

    // Cabecera de datos y bloque de condiciones.
    foreach (['Razón social', 'NIT', 'Sucursal', 'Orden de compra',
        'Tiempo de entrega', 'Forma de pago', 'Validez de oferta', 'Lugar de entrega'] as $campo) {
        expect($html)->toContain($campo);
    }

    expect($html)->toContain('ORDEN DE COMPRA O DEVOLVER FIRMADA');

    // Y ya no lleva direcciones: el formato no las tiene.
    expect($html)->not->toContain('Dirección de Facturación');
});

it('imprime los códigos que el ítem guardó, no los del catálogo', function () {
    $item = DB::table('quote_items')->where('quote_id', $this->cotizacion->id)->first();

    if (! $item) {
        DB::table('quote_items')->insert([
            'quote_id'   => $this->cotizacion->id, 'sku' => 'CM_00002', 'name' => 'Bolsa 50 L',
            'codigo_tr4' => '020117-0035', 'codigo_barra' => '7779970880037', 'medida' => '(65×80) cm',
            'unidad'     => 'PZA', 'quantity' => 25, 'price' => 13, 'total' => 325,
            'product_id' => \Webkul\Product\Models\Product::where('sku', 'CM_00002')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $html = htmlDelPdf($this->cotizacion);

    expect($html)->toContain('020117-0035')
        ->and($html)->toContain('7779970880037')
        ->and($html)->toContain('(65×80) cm');
});

it('resuelve los selects a su nombre en vez de imprimir el id', function () {
    // `lugar_entrega` guarda el id de la opción: sin Opciones::nombre() el PDF
    // sacaría «24» en el bloque de condiciones.
    $lugar = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
        ->getAttributeByCode('lugar_entrega', 'quotes')->options->firstWhere('name', 'Recoge en planta');

    $this->cotizacion->forceFill([])->save();

    app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
        'entity_type' => 'quotes', 'entity_id' => $this->cotizacion->id, 'lugar_entrega' => $lugar->id,
    ], collect([app(\Webkul\Attribute\Repositories\AttributeRepository::class)->getAttributeByCode('lugar_entrega', 'quotes')]));

    $html = htmlDelPdf($this->cotizacion);

    expect($html)->toContain('Recoge en planta')
        ->and($html)->not->toContain('>'.$lugar->id.'<');
});

it('oscurece un color de marca claro para que el texto del PDF se lea', function () {
    /*
     * El color de marca es texto en el PDF y lo edita el negocio desde
     * configuración. Un verde claro deja encabezados y firmas ilegibles sin
     * que nada avise. Auditoría UI/UX del 14/09: WCAG AA exige 4.5:1.
     *
     * Se prueba la regla de la plantilla con una vista mínima que la comparte,
     * porque la config de marca no se puede fijar desde un test.
     */
    $lum = function (string $hex): float {
        [$r, $g, $b] = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }, sscanf($hex, '#%02x%02x%02x'));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    };

    $contraste = fn (string $hex) => 1.05 / ($lum($hex) + 0.05);

    // La misma función que usa pdf.blade.php.
    $legible = function (string $hex) use ($lum): string {
        $rgb = sscanf($hex, '#%02x%02x%02x');

        for ($i = 0; $i < 12 && $lum(sprintf('#%02X%02X%02X', ...$rgb)) > 0.1833; $i++) {
            $rgb = array_map(fn ($c) => (int) round($c * 0.85), $rgb);
        }

        return sprintf('#%02X%02X%02X', ...$rgb);
    };

    // Un color claro que NO pasa (yellowgreen ≈ 1.9:1) queda legible.
    expect($contraste('#9ACD32'))->toBeLessThan(4.5);
    expect($contraste($legible('#9ACD32')))->toBeGreaterThanOrEqual(4.5);

    // Los colores reales de las marcas no se tocan: ya pasan.
    foreach (['#002677', '#3e6950'] as $real) {
        expect($legible($real))->toBe(strtoupper($real));
    }

    // Y la plantilla lleva la regla.
    expect(file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/quotes/pdf.blade.php')))
        ->toContain('$brandColor = $legible($brandColor);');
});
