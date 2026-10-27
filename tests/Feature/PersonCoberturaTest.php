<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/**
 * Fase D: en la ficha, todo lo del seguro en un bloque «Cobertura» (CI,
 * aseguradoras con su estado, verificar, editar y buscar en otras).
 */
beforeEach(function () {
    $this->admin = User::find(1);
    $this->ciAttr = Attribute::where('code', 'ci_paciente')->first();
    $this->seguroAttr = Attribute::where('code', 'seguro_paciente')->first();

    if (! $this->admin || ! $this->ciAttr || ! $this->seguroAttr) {
        $this->markTestSkipped('Faltan el usuario 1 o los atributos de seguro.');
    }

    $this->person = Person::query()->latest('id')->first();
});

function fichaCobertura(Person $person): string
{
    return actingAs(test()->admin, 'user')
        ->get(route('admin.contacts.persons.view', $person->id))
        ->assertOk()
        ->getContent();
}

it('la ficha muestra el bloque «Cobertura» con el CI y las rutas que usa', function () {
    $ci = (string) random_int(1000000, 9999999);
    $opcion = AttributeOption::where('attribute_id', $this->seguroAttr->id)->where('name', 'Nacional Vida')->first()
        ?: AttributeOption::create(['attribute_id' => $this->seguroAttr->id, 'name' => 'Nacional Vida', 'sort_order' => 99]);

    app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
        'entity_type'     => 'persons',
        'entity_id'       => $this->person->id,
        'ci_paciente'     => $ci,
        'seguro_paciente' => [$opcion->id],
    ]);

    expect(fichaCobertura($this->person->fresh()))
        ->toContain('id="cobertura-titulo"')
        ->toContain('initial-ci="'.$ci.'"')
        ->toContain('ci-url="'.route('admin.contacts.persons.verify_insurance_ci').'"')
        ->toContain('update-url="'.route('admin.contacts.persons.update_insurance', $this->person->id).'"')
        ->toContain('"name":"Nacional Vida"');
});

it('«Acerca del paciente» ya no repite CI, seguro ni estado del seguro', function () {
    $acerca = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/contacts/persons/view/attributes.blade.php'));

    expect($acerca)->toContain("'ci_paciente', 'seguro_paciente', 'estado_seguro_paciente']]");
});

it('«Verificar ahora» y «Editar» reusan el flujo del botón Seguro', function () {
    $verify = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/contacts/persons/view/insurance-verify.blade.php'));
    $cobertura = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/contacts/persons/view/insurance-statuses.blade.php'));

    expect($verify)
        ->toContain("this.\$emitter.on('cobertura:verificar', () => this.initVerification());")
        ->toContain("this.\$emitter.on('cobertura:editar', () => this.openCapture());");

    expect($cobertura)
        ->toContain("\$emitter.emit('cobertura:verificar')")
        ->toContain("\$emitter.emit('cobertura:editar')")
        // «Agregar» guarda junto a los que ya tenía, no los reemplaza.
        ->toContain('seguro_paciente: [...new Set([...this.seguros.map((s) => Number(s.id)), resultado.seguro_id])],');
});

/**
 * El botón «Sincronizar con SMD» de la ficha se registraba en un <script>
 * clásico, antes de que exista la app de Vue: fallaba y nunca aparecía.
 */
it('el componente de sincronizar con SMD se registra como módulo y se cierra', function () {
    $html = fichaCobertura($this->person);

    expect($html)
        ->toContain('></v-smd-sync>')
        ->toMatch('/<script type="module">\s*app\.component\(\'v-smd-sync\'/')
        ->not->toMatch('/<script>\s*app\.component\(\'v-smd-sync\'/');
});
