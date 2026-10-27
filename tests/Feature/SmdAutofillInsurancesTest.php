<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->admin = User::find(1);

    if (! $this->admin) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->seguroAttr = Attribute::where('code', 'seguro_paciente')->first();

    if (! $this->seguroAttr) {
        $this->markTestSkipped('No existe el atributo seguro_paciente.');
    }
});

function opcionSeguro(Attribute $atributo, string $nombre): AttributeOption
{
    return AttributeOption::where('attribute_id', $atributo->id)->where('name', $nombre)->first()
        ?: AttributeOption::create(['attribute_id' => $atributo->id, 'name' => $nombre, 'sort_order' => 99]);
}

/**
 * SMD guarda las aseguradoras como lista (extra.insurance) y el campo Seguro
 * es multiselect: el autocompletar necesita todas, no solo la primera.
 */
it('searchSmd devuelve todas las aseguradoras de SMD y conserva la primera en insurance', function () {
    Http::fake([
        '*/patients*' => Http::response([
            'data' => [[
                '_id'      => 'smd-varios-seguros',
                'name'     => 'Ana',
                'lastName' => 'Rojas',
                'phone'    => '77722222',
                'personID' => '7654321',
                'extra'    => ['insurance' => ['Membresía Odontoking', 'Alianza', '', null]],
            ]],
        ], 200),
    ]);

    actingAs($this->admin, 'user')
        ->getJson(route('admin.contacts.persons.search_smd', ['q' => '77722222']))
        ->assertOk()
        ->assertJson([
            'found'      => true,
            'insurance'  => 'Membresía Odontoking',
            'insurances' => ['Membresía Odontoking', 'Alianza'],
        ]);
});

it('searchSmd devuelve insurances vacío si SMD no trae aseguradoras', function () {
    Http::fake([
        '*/patients*' => Http::response(['data' => [['_id' => 'smd-sin-seguro', 'name' => 'Luis']]], 200),
    ]);

    actingAs($this->admin, 'user')
        ->getJson(route('admin.contacts.persons.search_smd', ['q' => '77733333']))
        ->assertOk()
        ->assertJson(['found' => true, 'insurance' => null, 'insurances' => []]);
});

/**
 * Verificación rápida (crear paciente) con varios seguros elegidos: un
 * resultado por aseguradora y el veredicto general de la mejor cobertura.
 */
it('verify-insurance-quick con varios seguros devuelve un resultado por aseguradora y el mejor estado', function () {
    // Cédula única: no reusar respuestas en caché de otras corridas.
    $ci = (string) random_int(10000000, 99999999);

    // Nacional Vida lee su padrón de Google Sheets (ya no n8n).
    $this->mock(\Webkul\Admin\Services\Insurance\GoogleSheetsInsuranceSource::class)
        ->shouldReceive('lookup')
        ->andReturn([
            'success' => true,
            'data'    => [['ESTADO' => 'VIGENTE', 'NOMBRE COMPLETO' => 'PACIENTE VARIOS']],
        ]);

    $nacional = opcionSeguro($this->seguroAttr, 'Nacional Vida');
    $noTiene = opcionSeguro($this->seguroAttr, 'No tiene');

    $respuesta = actingAs($this->admin, 'user')
        ->postJson(route('admin.contacts.persons.verify_insurance_quick'), [
            'ci_paciente'     => $ci,
            'seguro_paciente' => [$noTiene->id, $nacional->id],
        ])
        ->assertOk()
        ->assertJson(['status' => 'VIGENTE', 'badge' => 'success'])
        ->json();

    expect($respuesta['results'])->toHaveCount(2)
        ->and(collect($respuesta['results'])->pluck('seguro_id')->sort()->values()->all())
        ->toBe(collect([$noTiene->id, $nacional->id])->sort()->values()->all());
});

it('verify-insurance-quick rechaza ids de seguro que no existen, también en lista', function () {
    actingAs($this->admin, 'user')
        ->postJson(route('admin.contacts.persons.verify_insurance_quick'), [
            'ci_paciente'     => '5856583',
            'seguro_paciente' => [999999999],
        ])
        ->assertStatus(422);

    actingAs($this->admin, 'user')
        ->postJson(route('admin.contacts.persons.verify_insurance_quick'), [
            'ci_paciente'     => '5856583',
            'seguro_paciente' => 999999999,
        ])
        ->assertStatus(422);
});

/**
 * Front: el autocompletar pone los seguros como chips del multiselect (antes
 * buscaba un <select> que no existe y el seguro nunca se cargaba) y los avisos
 * salen por window.emitter (window.__vueApp no existe).
 */
it('el autocompletar de SMD carga los seguros en el multiselect y avisa por window.emitter', function () {
    $checker = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/contacts/persons/partials/smd-checker.blade.php'));
    $multiselect = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/components/attributes/edit/multiselect.blade.php'));

    expect($checker)
        ->toContain("window.emitter.emit('multiselect:agregar', {")
        ->toContain("window.emitter?.emit('add-flash', { type, message });")
        ->not->toContain('__vueApp')
        ->not->toContain('setSelectByText');

    expect($multiselect)
        ->toContain("this.\$emitter.on('multiselect:agregar', this.agregarPorNombre);")
        ->toContain(".normalize('NFD')");
});

it('el botón Verificar Seguro de crear paciente manda todos los seguros elegidos', function () {
    actingAs($this->admin, 'user')
        ->get(route('admin.contacts.persons.create'))
        ->assertOk()
        ->assertSee("document.querySelectorAll('[name=\"seguro_paciente[]\"]')", false)
        ->assertSee('seguro_paciente: seguros.length === 1 ? seguros[0] : seguros,', false);
});
