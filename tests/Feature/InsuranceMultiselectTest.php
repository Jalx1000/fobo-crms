<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\InsuranceService;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/**
 * Regresión: `seguro_paciente` está declarado como multiselect, pero varios
 * callers programáticos (InsuranceController, EntitySync, calendario) le pasaban
 * un id escalar. AttributeValueRepository::save hacía implode(',', int) y
 * reventaba con:
 *
 *   TypeError: implode(): Argument #2 ($array) must be of type ?array, int given
 */
beforeEach(function () {
    $this->admin = User::find(1);

    $this->ciAttr = Attribute::where('code', 'ci_paciente')->first() ?: Attribute::create([
        'code' => 'ci_paciente', 'name' => 'CI', 'type' => 'text', 'entity_type' => 'persons', 'admin_name' => 'CI',
    ]);

    $this->seguroAttr = Attribute::where('code', 'seguro_paciente')->first() ?: Attribute::create([
        'code' => 'seguro_paciente', 'name' => 'Seguro', 'type' => 'multiselect', 'entity_type' => 'persons', 'admin_name' => 'Seguro',
    ]);

    $this->makeOption = function (string $name) {
        return DB::table('attribute_options')->insertGetId([
            'name'         => $name,
            'sort_order'   => 1,
            'attribute_id' => $this->seguroAttr->id,
        ]);
    };
});

it('guarda un atributo multiselect cuando el caller manda un id escalar', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $optionId = ($this->makeOption)('Alianza');
    $person = Person::create(['name' => 'Paciente Escalar '.uniqid()]);

    // Esto es exactamente lo que reventaba en producción.
    app(AttributeValueRepository::class)->save([
        'entity_type'     => 'persons',
        'entity_id'       => $person->id,
        'seguro_paciente' => $optionId,
    ], collect([$this->seguroAttr]));

    expect(DB::table('attribute_values')
        ->where('entity_id', $person->id)
        ->where('attribute_id', $this->seguroAttr->id)
        ->value('text_value')
    )->toBe((string) $optionId);
});

it('guarda varios seguros como CSV cuando el caller manda un array', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');
    $person = Person::create(['name' => 'Paciente Multi '.uniqid()]);

    app(AttributeValueRepository::class)->save([
        'entity_type'     => 'persons',
        'entity_id'       => $person->id,
        'seguro_paciente' => [$alianza, $nacional],
    ], collect([$this->seguroAttr]));

    expect(DB::table('attribute_values')
        ->where('entity_id', $person->id)
        ->where('attribute_id', $this->seguroAttr->id)
        ->value('text_value')
    )->toBe("{$alianza},{$nacional}");
});

it('descarta valores vacíos al normalizar un multiselect', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $person = Person::create(['name' => 'Paciente Vacios '.uniqid()]);

    app(AttributeValueRepository::class)->save([
        'entity_type'     => 'persons',
        'entity_id'       => $person->id,
        'seguro_paciente' => [$alianza, '', null],
    ], collect([$this->seguroAttr]));

    expect(DB::table('attribute_values')
        ->where('entity_id', $person->id)
        ->where('attribute_id', $this->seguroAttr->id)
        ->value('text_value')
    )->toBe((string) $alianza);
});

it('getPersonInsurances explota el CSV respetando el orden guardado', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente Orden '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$nacional},{$alianza}",
    ]);

    $insurances = app(InsuranceService::class)
        ->getPersonInsurances($person->fresh(), $this->seguroAttr->fresh());

    expect(array_column($insurances, 'name'))->toBe(['Nacional Vida', 'Alianza'])
        ->and(array_column($insurances, 'id'))->toBe([$nacional, $alianza]);
});

/**
 * Con varios seguros se verifica CADA uno y se devuelve el detalle en `results`,
 * más un veredicto agregado donde gana la mejor cobertura.
 */
it('verifica todas las aseguradoras del paciente, una por una', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente Dos Seguros '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->ciAttr->id, 'entity_type' => 'persons', 'text_value' => '5856583',
    ]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$alianza},{$nacional}",
    ]);

    $result = app(InsuranceService::class)->verify($person->id);

    expect($result)->toHaveKey('results')
        ->and($result['results'])->toHaveCount(2)
        ->and(array_column($result['results'], 'seguro_name'))->toBe(['Alianza', 'Nacional Vida'])
        ->and(array_column($result['results'], 'seguro_id'))->toBe([$alianza, $nacional]);
});

it('ignora "No tiene" cuando el paciente además tiene un seguro real', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $noTiene = ($this->makeOption)('No tiene');
    $alianza = ($this->makeOption)('Alianza');

    $person = Person::create(['name' => 'Paciente Mixto '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->ciAttr->id, 'entity_type' => 'persons', 'text_value' => '5856583',
    ]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$noTiene},{$alianza}",
    ]);

    $result = app(InsuranceService::class)->verify($person->id);

    expect(array_column($result['results'], 'seguro_name'))->toBe(['Alianza']);
});

/**
 * El agregado colapsa N resultados en un veredicto: gana la mejor cobertura,
 * porque si CUALQUIER seguro está vigente el paciente está cubierto.
 */
it('el veredicto agregado se queda con la mejor cobertura', function () {
    $service = app(InsuranceService::class);

    $summarize = new \ReflectionMethod($service, 'summarize');

    $summary = $summarize->invoke($service, [
        ['status' => 'NO_REGISTRADO', 'badge' => 'danger', 'success' => false, 'data' => null, 'seguro_name' => 'Nacional Vida', 'seguro_id' => 1],
        ['status' => 'VIGENTE', 'badge' => 'success', 'success' => true, 'data' => null, 'seguro_name' => 'Alianza', 'seguro_id' => 2],
    ]);

    expect($summary['status'])->toBe('VIGENTE')
        ->and($summary['results'])->toHaveCount(2)
        // Con varias aseguradoras el mensaje resume el detalle de cada una.
        ->and($summary['message'])->toBe('Nacional Vida: NO_REGISTRADO · Alianza: VIGENTE');
});

it('INDETERMINADO no tapa un resultado real de otra aseguradora', function () {
    $service = app(InsuranceService::class);

    $summary = (new \ReflectionMethod($service, 'summarize'))->invoke($service, [
        ['status' => 'INDETERMINADO', 'badge' => null, 'success' => false, 'data' => null, 'seguro_name' => 'Alianza', 'seguro_id' => 1],
        ['status' => 'NO_REGISTRADO', 'badge' => 'danger', 'success' => false, 'data' => null, 'seguro_name' => 'Nacional Vida', 'seguro_id' => 2],
    ]);

    expect($summary['status'])->toBe('NO_REGISTRADO');
});

it('cachea cada aseguradora por separado', function () {
    $service = app(InsuranceService::class);

    expect($service->cacheKeyFor(7, 112, '5856583'))
        ->not->toBe($service->cacheKeyFor(7, 118, '5856583'));
});

/**
 * `persons.insurance_status` solo guarda el agregado. El estado de CADA aseguradora
 * vive en `person_insurance_statuses`, una fila por par (paciente, aseguradora).
 */
it('persiste el estado de cada aseguradora por separado', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente Persist '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->ciAttr->id, 'entity_type' => 'persons', 'text_value' => '5856583',
    ]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$alianza},{$nacional}",
    ]);

    app(InsuranceService::class)->verify($person->id);

    $rows = DB::table('person_insurance_statuses')
        ->where('person_id', $person->id)
        ->orderBy('attribute_option_id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('attribute_option_id')->all())->toBe(collect([$alianza, $nacional])->sort()->values()->all())
        ->and($rows->pluck('seguro_name')->sort()->values()->all())->toBe(['Alianza', 'Nacional Vida'])
        ->and($rows->every(fn ($r) => ! empty($r->status)))->toBeTrue()
        ->and($rows->every(fn ($r) => ! is_null($r->checked_at)))->toBeTrue();
});

it('re-verificar actualiza la fila en vez de duplicarla', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');

    $person = Person::create(['name' => 'Paciente Reverify '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->ciAttr->id, 'entity_type' => 'persons', 'text_value' => '5856583',
    ]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id, 'entity_type' => 'persons', 'text_value' => (string) $alianza,
    ]);

    $service = app(InsuranceService::class);
    $service->verify($person->id);
    $service->verify($person->id);

    expect(DB::table('person_insurance_statuses')->where('person_id', $person->id)->count())->toBe(1);
});

it('quitarle un seguro al paciente borra su estado persistido', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente Quita '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->ciAttr->id, 'entity_type' => 'persons', 'text_value' => '5856583',
    ]);
    $seguroValue = $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$alianza},{$nacional}",
    ]);

    $service = app(InsuranceService::class);
    $service->verify($person->id);

    expect(DB::table('person_insurance_statuses')->where('person_id', $person->id)->count())->toBe(2);

    // Le quitan Nacional Vida
    $seguroValue->update(['text_value' => (string) $alianza]);
    $service->verify($person->fresh()->id);

    $remaining = DB::table('person_insurance_statuses')->where('person_id', $person->id)->pluck('attribute_option_id');

    expect($remaining->all())->toBe([$alianza]);
});

it('insurance-status expone el estado de cada aseguradora', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente Endpoint Estados '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$alianza},{$nacional}",
    ]);

    DB::table('person_insurance_statuses')->insert([
        [
            'person_id' => $person->id, 'attribute_option_id' => $alianza, 'seguro_name' => 'Alianza',
            'status'    => 'VIGENTE', 'badge' => 'success', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'person_id' => $person->id, 'attribute_option_id' => $nacional, 'seguro_name' => 'Nacional Vida',
            'status'    => 'NO_REGISTRADO', 'badge' => 'danger', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $response = actingAs($this->admin, 'user')
        ->getJson(route('admin.contacts.persons.insurance_status', $person->id))
        ->assertStatus(200)
        ->json('seguros');

    expect(array_column($response, 'status'))->toBe(['VIGENTE', 'NO_REGISTRADO']);
});

it('la ficha del paciente muestra el estado de cada aseguradora', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente Ficha '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$alianza},{$nacional}",
    ]);

    DB::table('person_insurance_statuses')->insert([
        [
            'person_id' => $person->id, 'attribute_option_id' => $alianza, 'seguro_name' => 'Alianza',
            'status'    => 'VIGENTE', 'badge' => 'success', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'person_id' => $person->id, 'attribute_option_id' => $nacional, 'seguro_name' => 'Nacional Vida',
            'status'    => 'NO_REGISTRADO', 'badge' => 'danger', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    actingAs($this->admin, 'user')
        ->get(route('admin.contacts.persons.view', $person->id))
        ->assertStatus(200)
        ->assertSee('Seguros del paciente')
        // El estado inicial viaja serializado al componente Vue.
        ->assertSee('VIGENTE', false)
        ->assertSee('NO_REGISTRADO', false);
});

it('cuando solo hay "No tiene" devuelve esa opción para responder SIN_SEGURO', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $noTiene = ($this->makeOption)('No tiene');

    $person = Person::create(['name' => 'Paciente Sin '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => (string) $noTiene,
    ]);

    expect(app(InsuranceService::class)->verify($person->id)['status'])->toBe('SIN_SEGURO');
});

it('devuelve null cuando ningún seguro del paciente tiene integración', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $desconocido = ($this->makeOption)('Aseguradora Inexistente SA');

    $person = Person::create(['name' => 'Paciente Sin Driver '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => (string) $desconocido,
    ]);

    expect(app(InsuranceService::class)->verify($person->id)['status'])->toBe('INDETERMINADO');
});

it('update-insurance acepta un id escalar sin lanzar TypeError', function () {
    $optionId = ($this->makeOption)('Alianza');
    $person = Person::create(['name' => 'Paciente Endpoint '.uniqid()]);

    actingAs($this->admin, 'user')
        ->postJson(route('admin.contacts.persons.update_insurance', $person->id), [
            'ci_paciente'     => '5856583',
            'seguro_paciente' => $optionId,
        ])
        ->assertStatus(200);

    expect(DB::table('attribute_values')
        ->where('entity_id', $person->id)
        ->where('attribute_id', $this->seguroAttr->id)
        ->exists()
    )->toBeTrue();
});

it('update-insurance acepta una lista de seguros', function () {
    if ($this->seguroAttr->type !== 'multiselect') {
        $this->markTestSkipped('seguro_paciente no es multiselect en esta instalación.');
    }

    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');
    $person = Person::create(['name' => 'Paciente Endpoint Multi '.uniqid()]);

    actingAs($this->admin, 'user')
        ->postJson(route('admin.contacts.persons.update_insurance', $person->id), [
            'ci_paciente'     => '5856583',
            'seguro_paciente' => [$alianza, $nacional],
        ])
        ->assertStatus(200);

    expect(DB::table('attribute_values')
        ->where('entity_id', $person->id)
        ->where('attribute_id', $this->seguroAttr->id)
        ->value('text_value')
    )->toBe("{$alianza},{$nacional}");
});

it('update-insurance rechaza una lista vacía de seguros', function () {
    $person = Person::create(['name' => 'Paciente Sin Seguro '.uniqid()]);

    actingAs($this->admin, 'user')
        ->postJson(route('admin.contacts.persons.update_insurance', $person->id), [
            'ci_paciente'     => '5856583',
            'seguro_paciente' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seguro_paciente');
});
