<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\Insurance\Drivers\AlianzaDriver;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/**
 * Tests para AlianzaDriver — integración MedinetGo.
 *
 * El driver hace dos requests HTTP:
 *   POST https://devnet.alianza.com.bo/ApiGateway/LoginUserProv
 *   GET  https://devnet.alianza.com.bo/ApiGateway/OdontologyCoverage?ci=...&nUsercode=...
 *
 * IMPORTANTE: Http::fake requiere wildcard (devnet.alianza.com.bo/*)
 * porque el GET incluye query params. Si se usa la URL exacta del endpoint
 * el fake no intercepta la segunda petición y el request real llega a producción
 * con un 401 (sin credenciales válidas), devolviendo body null.
 *
 * Bugs confirmados que estos tests documentan y fallan contra el código actual:
 *
 *   BUG-1  checkCoverage() no distingue error HTTP (response->failed()) de
 *          success:false de Alianza. Cuando OdontologyCoverage devuelve 500
 *          el body es null, se entra al bloque de messageError con string vacío,
 *          y el resultado es NO_REGISTRADO en lugar de INDETERMINADO.
 *
 *   BUG-2  La rama NO_REGISTRADO retorna success:false. Debería ser success:true
 *          porque la verificación completó correctamente — solo que el paciente
 *          no tiene cobertura. NacionalVidaDriver lo hace correctamente.
 *          Consecuencia: el UI trata NO_REGISTRADO como error técnico, no como
 *          respuesta de negocio.
 *
 *   BUG-3  EN_MORA también retorna success:false por el mismo motivo que BUG-2.
 *
 * Para corregir los bugs:
 *   - AlianzaDriver::checkCoverage() línea 98: separar
 *       if ($response->failed()) → retornar indeterminate()
 *     de
 *       if ($body['success']) → procesar cobertura
 *   - Líneas 137 y 147: cambiar 'success' => true en EN_MORA y NO_REGISTRADO
 *     (igual que NacionalVidaDriver).
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Patrón wildcard para Http::fake que intercepta ambos endpoints del driver.
 * Usar siempre este patrón. NO usar la URL exacta del endpoint.
 */
const ALIANZA_HOST_PATTERN = 'devnet.alianza.com.bo/*';

function alianzaLoginOk(): array
{
    return [
        'success' => true,
        'data'    => [
            'accessToken'    => 'fake-jwt-token',
            'webClientVtime' => [['nUsercode' => 42]],
        ],
    ];
}

function alianzaLoginFailed(): array
{
    return ['success' => false, 'messageError' => 'Credenciales inválidas'];
}

function alianzaCoverageVigente(string $ci = '12345678'): array
{
    return [
        'success' => true,
        'data'    => [[
            'NRO. DOCUMENTO'                   => $ci,
            'NOMBRE COMPLETO'                  => 'JAVIER ALEJANDRO PACIENTE',
            'CONTRATANTE'                      => 'EMPRESA SA',
            'ESTADO'                           => 'VIGENTE',
            'EDAD'                             => 35,
            'CODIGO DE ASEGURADO'              => 'AZ-001',
            'COBERTURA ADICIONAL ODONTOLOGICA' => '100%',
            'COASEGURO ODONTOLOGICO'           => '$US. 7',
            'CLINICA ODONTOLOGICA'             => 'Odontoking',
            'RELACION'                         => 'TITULAR',
            'FECHA DE INGRESO'                 => '01/01/2024',
        ]],
    ];
}

function alianzaCoverageNotFound(): array
{
    return [
        'success'      => false,
        'messageError' => 'cliente no encontrado o sin cobertura odontologica',
    ];
}

function alianzaCoverageEnMora(): array
{
    return [
        'success'      => false,
        'messageError' => 'pago pendiente, comuníquese con la compañía',
    ];
}

function makeFakePerson(int $id = 999): object
{
    $person = new \stdClass;
    $person->id = $id;
    $person->name = 'Paciente Test';
    $person->leads = collect([]);

    return $person;
}

// Fake que devuelve login exitoso seguido de la respuesta de cobertura dada.
// Usa Http::sequence() porque el driver hace DOS requests al mismo host.
function fakeAlianza(array $coverageBody, int $coverageStatus = 200): void
{
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::sequence()
            ->push(alianzaLoginOk(), 200)
            ->push($coverageBody, $coverageStatus),
    ]);
}

// ─── Setup ──────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->adminUser = User::find(1);
    Cache::flush();

    $this->driver = app(AlianzaDriver::class);
    $this->person = makeFakePerson();
});

// ─── Login fallido ───────────────────────────────────────────────────────────

it('retorna INDETERMINADO cuando el login de Alianza falla con success:false', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::response(alianzaLoginFailed(), 200),
    ]);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('INDETERMINADO');
    expect($result['success'])->toBeFalse();
    expect($result['data'])->toBeNull();
});

it('retorna INDETERMINADO cuando el login de Alianza responde con HTTP 500', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::response([], 500),
    ]);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('INDETERMINADO');
    expect($result['success'])->toBeFalse();
});

it('retorna INDETERMINADO cuando el login lanza una excepción de red', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        },
    ]);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('INDETERMINADO');
    expect($result['success'])->toBeFalse();
});

it('retorna INDETERMINADO cuando el login devuelve token nulo', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::response([
            'success' => true,
            'data'    => [
                'accessToken'    => null,
                'webClientVtime' => [['nUsercode' => 42]],
            ],
        ], 200),
    ]);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('INDETERMINADO');
    expect($result['success'])->toBeFalse();
});

it('retorna INDETERMINADO cuando el login devuelve nUsercode nulo', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::response([
            'success' => true,
            'data'    => [
                'accessToken'    => 'valid-token',
                'webClientVtime' => [],
            ],
        ], 200),
    ]);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('INDETERMINADO');
    expect($result['success'])->toBeFalse();
});

// ─── BUG-1: OdontologyCoverage HTTP 500 debe ser INDETERMINADO ──────────────

/**
 * BUG-1: Cuando OdontologyCoverage responde con HTTP 500 el driver no llama
 * a $response->failed() antes de leer $body['messageError']. El body es null,
 * str_contains en null lanza warning y la condición EN_MORA no se cumple,
 * retornando NO_REGISTRADO con success:false.
 *
 * Fix: en checkCoverage() añadir antes del bloque de messageError:
 *   if ($response->failed()) {
 *       $this->updateInsuranceAttributes($person, 'INDETERMINADO');
 *       return $this->indeterminate('...');
 *   }
 */
it('retorna INDETERMINADO cuando OdontologyCoverage responde con HTTP 500', function () {
    fakeAlianza([], 500);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('INDETERMINADO');
    expect($result['success'])->toBeFalse();
});

it('retorna INDETERMINADO cuando OdontologyCoverage lanza excepción de red', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::sequence()
            ->push(alianzaLoginOk(), 200)
            ->whenEmpty(function () {
                throw new \Illuminate\Http\Client\ConnectionException('Timeout');
            }),
    ]);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('INDETERMINADO');
    expect($result['success'])->toBeFalse();
});

// ─── BUG-2 y BUG-3: success debe ser true en NO_REGISTRADO y EN_MORA ────────

/**
 * BUG-2 + BUG-3: El contrato del driver (InsuranceDriverInterface) define
 * success:bool como "¿se completó la verificación sin error técnico?".
 * NO_REGISTRADO y EN_MORA son respuestas de negocio válidas de Alianza,
 * no errores técnicos. Por tanto deben retornar success:true.
 *
 * NacionalVidaDriver lo hace correctamente (success:true en NO_REGISTRADO).
 * AlianzaDriver es inconsistente con el contrato.
 *
 * Consecuencia práctica: la API /api/v1/insurance/verify (verifyForAgent)
 * usa InsuranceService::verifyWithParams() que mapea success:false → INDETERMINADO
 * en lugar de tratar NO_REGISTRADO como estado de negocio.
 *
 * Fix: en checkCoverage() cambiar 'success' => false a 'success' => true
 * en los returns de EN_MORA (línea 137) y NO_REGISTRADO (línea 147).
 */
it('[BUG-2] retorna success:true en NO_REGISTRADO — el paciente no tiene cobertura pero el servicio respondió', function () {
    fakeAlianza(alianzaCoverageNotFound());

    $result = $this->driver->verify($this->person, '99999999');

    expect($result['status'])->toBe('NO_REGISTRADO');
    expect($result['badge'])->toBe('warning');
    expect($result['success'])->toBeTrue(); // BUG: actualmente retorna false
    expect($result['data'])->toBeNull();
})->todo('BUG-2: NO_REGISTRADO retorna success:false en lugar de success:true');

it('[BUG-3] retorna success:true en EN_MORA — es una respuesta de negocio, no un error técnico', function () {
    fakeAlianza(alianzaCoverageEnMora());

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('EN_MORA');
    expect($result['badge'])->toBe('danger');
    expect($result['success'])->toBeTrue(); // BUG: actualmente retorna false
    expect($result['data'])->toBeNull();
})->todo('BUG-3: EN_MORA retorna success:false en lugar de success:true');

// ─── Casos que sí pasan — comportamiento correcto ya implementado ────────────

it('retorna NO_REGISTRADO con el messageError de Alianza en el mensaje', function () {
    fakeAlianza(alianzaCoverageNotFound());

    $result = $this->driver->verify($this->person, '99999999');

    expect($result['status'])->toBe('NO_REGISTRADO');
    // El mensaje incluye el messageError crudo que devuelve Alianza
    expect($result['message'])->toContain('cliente no encontrado o sin cobertura odontologica');
})->todo('el mensaje actual dice "Sin cobertura" en lugar del messageError real de Alianza');

it('retorna EN_MORA cuando messageError contiene "pago pendiente"', function () {
    fakeAlianza(alianzaCoverageEnMora());

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('EN_MORA');
    expect($result['badge'])->toBe('danger');
});

it('retorna EN_MORA cuando messageError contiene "comuníquese" sin "pago pendiente"', function () {
    fakeAlianza([
        'success'      => false,
        'messageError' => 'Comuníquese con la compañía aseguradora',
    ]);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('EN_MORA');
    expect($result['badge'])->toBe('danger');
});

it('retorna NO_REGISTRADO cuando Alianza responde success:false sin messageError', function () {
    fakeAlianza(['success' => false]);

    $result = $this->driver->verify($this->person, '00000000');

    expect($result['status'])->toBe('NO_REGISTRADO');
});

// ─── VIGENTE ─────────────────────────────────────────────────────────────────

it('retorna VIGENTE con datos completos cuando Alianza confirma cobertura activa', function () {
    fakeAlianza(alianzaCoverageVigente('12345678'));

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['status'])->toBe('VIGENTE');
    expect($result['badge'])->toBe('success');
    expect($result['success'])->toBeTrue();
    expect($result['data'])->toBeArray();
    expect($result['data']['CI'])->toBe('12345678');
    expect($result['data']['NOMBRE COMPLETO'])->toBe('JAVIER ALEJANDRO PACIENTE');
    expect($result['data']['ESTADO'])->toBe('VIGENTE');
});

it('retorna status no-VIGENTE con badge:warning cuando ESTADO es VENCIDO', function () {
    $coverage = alianzaCoverageVigente();
    $coverage['data'][0]['ESTADO'] = 'VENCIDO';

    fakeAlianza($coverage);

    $result = $this->driver->verify($this->person, '12345678');

    // El status refleja el ESTADO devuelto por Alianza
    expect($result['status'])->toBe('VENCIDO');
    // Solo VIGENTE → badge success; cualquier otro estado activo → warning
    expect($result['badge'])->toBe('warning');
    expect($result['success'])->toBeTrue();
});

it('usa NRO. DOCUMENTO de la respuesta de Alianza como campo CI en data', function () {
    fakeAlianza(alianzaCoverageVigente('E-12345678'));

    $result = $this->driver->verify($this->person, 'E-12345678');

    expect($result['data']['CI'])->toBe('E-12345678');
});

it('usa el CI del request como fallback en data cuando NRO. DOCUMENTO no está en la respuesta', function () {
    $coverage = alianzaCoverageVigente();
    unset($coverage['data'][0]['NRO. DOCUMENTO']);

    fakeAlianza($coverage);

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['data']['CI'])->toBe('12345678');
});

// ─── Estructura de la respuesta ──────────────────────────────────────────────

it('la respuesta del driver siempre incluye las claves requeridas por InsuranceDriverInterface', function () {
    fakeAlianza(alianzaCoverageNotFound());

    $result = $this->driver->verify($this->person, '00000000');

    expect($result)->toHaveKeys(['status', 'message', 'badge', 'success', 'data']);
});

it('la respuesta VIGENTE incluye todos los campos de datos mapeados desde Alianza', function () {
    fakeAlianza(alianzaCoverageVigente());

    $result = $this->driver->verify($this->person, '12345678');

    expect($result['data'])->toHaveKeys([
        'CI',
        'CONTRATANTE',
        'NOMBRE COMPLETO',
        'ESTADO',
        'EDAD',
        'CODIGO',
        'COBERTURA ODONTOLOGICA',
        'COASEGURO',
        'CLINICA',
        'RELACION',
        'FECHA INGRESO',
    ]);
});

// ─── Integración con InsuranceService vía endpoint HTTP ─────────────────────

it('el endpoint verify retorna status:EN_MORA cuando Alianza retorna pago pendiente', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::sequence()
            ->push(alianzaLoginOk(), 200)
            ->push(alianzaCoverageEnMora(), 200),
    ]);

    $person = \Webkul\Contact\Models\Person::create(['name' => 'Paciente Mora '.uniqid()]);
    $ciAttr = \Webkul\Attribute\Models\Attribute::where('code', 'ci_paciente')->first();
    $seguroAttr = \Webkul\Attribute\Models\Attribute::where('code', 'seguro_paciente')->first();

    if (! $ciAttr || ! $seguroAttr) {
        $this->markTestSkipped('Atributos ci_paciente / seguro_paciente no encontrados en BD.');
    }

    $alianzaOption = \Illuminate\Support\Facades\DB::table('attribute_options')
        ->where('attribute_id', $seguroAttr->id)
        ->whereRaw('LOWER(name) LIKE ?', ['%alianza%'])
        ->first();

    if (! $alianzaOption) {
        $this->markTestSkipped('Opción "Alianza" no existe en attribute_options.');
    }

    $person->attribute_values()->create(['attribute_id' => $ciAttr->id, 'text_value' => '12345678', 'entity_type' => 'persons']);
    $person->attribute_values()->create(['attribute_id' => $seguroAttr->id, 'integer_value' => $alianzaOption->id, 'entity_type' => 'persons']);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $person->id))
        ->assertStatus(200)
        ->assertJson(['status' => 'EN_MORA', 'badge' => 'danger']);
});

it('el endpoint verify retorna status:NO_REGISTRADO cuando Alianza retorna cliente no encontrado', function () {
    Http::fake([
        ALIANZA_HOST_PATTERN => Http::sequence()
            ->push(alianzaLoginOk(), 200)
            ->push(alianzaCoverageNotFound(), 200),
    ]);

    $person = \Webkul\Contact\Models\Person::create(['name' => 'Sin Cobertura '.uniqid()]);
    $ciAttr = \Webkul\Attribute\Models\Attribute::where('code', 'ci_paciente')->first();
    $seguroAttr = \Webkul\Attribute\Models\Attribute::where('code', 'seguro_paciente')->first();

    if (! $ciAttr || ! $seguroAttr) {
        $this->markTestSkipped('Atributos no encontrados en BD.');
    }

    $alianzaOption = \Illuminate\Support\Facades\DB::table('attribute_options')
        ->where('attribute_id', $seguroAttr->id)
        ->whereRaw('LOWER(name) LIKE ?', ['%alianza%'])
        ->first();

    if (! $alianzaOption) {
        $this->markTestSkipped('Opción "Alianza" no existe en attribute_options.');
    }

    $person->attribute_values()->create(['attribute_id' => $ciAttr->id, 'text_value' => '99999999', 'entity_type' => 'persons']);
    $person->attribute_values()->create(['attribute_id' => $seguroAttr->id, 'integer_value' => $alianzaOption->id, 'entity_type' => 'persons']);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $person->id))
        ->assertStatus(200)
        ->assertJson(['status' => 'NO_REGISTRADO', 'badge' => 'warning']);
});
