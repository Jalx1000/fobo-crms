<?php

/*
|--------------------------------------------------------------------------
| Seguros desde Google Sheets (Nacional Vida y Membresía Odontoking)
|--------------------------------------------------------------------------
| Reemplaza al webhook de n8n: se lee el padrón con una cuenta de servicio y
| se devuelven las filas como las devolvía n8n, así la lógica de los drivers
| (vigente / en mora / "MF HASTA EL …") no cambia. Google se simula.
*/

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\Insurance\Drivers\NacionalVidaDriver;
use Webkul\Admin\Services\Insurance\Drivers\OdontokingMembershipDriver;
use Webkul\Admin\Services\Insurance\GoogleSheetsInsuranceSource;

beforeEach(function () {
    // Cuenta de servicio de prueba con una clave real (Google se simula).
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->credentials = tempnam(sys_get_temp_dir(), 'gsa');
    file_put_contents($this->credentials, json_encode(['client_email' => 'crm@test.iam.gserviceaccount.com', 'private_key' => $pem]));

    config([
        'services.google_sheets.credentials_path'                => $this->credentials,
        'services.insurance_sheets.nacional_vida.spreadsheet_id' => 'sheet-nv-'.uniqid(),
        'services.insurance_sheets.membresia.spreadsheet_id'     => 'sheet-mb-'.uniqid(),
        'services.insurance.cache_enabled'                       => false,
    ]);
    Cache::flush();

    $this->sheets = [
        'sheet-nv' => [
            ['NRO. DOCUMENTO', 'NOMBRE COMPLETO', 'CONTRATANTE', 'ESTADO'],
            ['1234567 LP', 'JUAN PEREZ', 'EMPRESA X', 'VIGENTE'],
            ['7654321', 'ANA GOMEZ', 'EMPRESA Y', 'SUSPENDIDO'],
        ],
        'sheet-mb' => [
            ['CI', 'Nombre', 'Observaciones', 'Seguro'],
            ['5555555', 'Pedro Rojas', 'MF HASTA EL '.now()->addMonth()->format('d/m/Y'), 'Membresia'],
            ['6666666', 'Lucia Vaca', 'MF HASTA EL '.now()->subMonth()->format('d/m/Y'), 'Membresia'],
        ],
    ];

    Http::fake([
        // Http::fake() acumula stubs y gana el primero: lo que cambia por test va por estado.
        'oauth2.googleapis.com/token' => fn () => $this->googleDown
            ? Http::response(['error' => 'invalid_grant'], 400)
            : Http::response(['access_token' => 'tok-123', 'expires_in' => 3600]),
        'sheets.googleapis.com/*'     => function ($request) {
            $id = explode('/', parse_url($request->url(), PHP_URL_PATH))[3];

            return Http::response(['values' => $this->sheets[substr($id, 0, 8)] ?? []]);
        },
    ]);

    $this->googleDown = false;
    $this->person = (object) ['id' => 0, 'name' => 'Test', 'leads' => collect()];
});

afterEach(fn () => @unlink($this->credentials));

it('Nacional Vida: encuentra la cédula aunque la planilla tenga la sigla (1234567 LP)', function () {
    $result = app(NacionalVidaDriver::class)->verify($this->person, '1234567');

    expect($result['status'])->toBe('VIGENTE')
        ->and($result['data']['NOMBRE COMPLETO'])->toBe('JUAN PEREZ');
});

it('Nacional Vida: suspendido y no registrado', function () {
    expect(app(NacionalVidaDriver::class)->verify($this->person, '7654321')['status'])->toBe('EN_MORA')
        ->and(app(NacionalVidaDriver::class)->verify($this->person, '9999999')['status'])->toBe('NO_REGISTRADO');
});

it('Membresía: vigente o vencida según "MF HASTA EL"', function () {
    expect(app(OdontokingMembershipDriver::class)->verify($this->person, '5555555')['status'])->toBe('VIGENTE')
        ->and(app(OdontokingMembershipDriver::class)->verify($this->person, '6666666')['status'])->toBe('VENCIDO');
});

it('pide a Google con la cuenta de servicio, solo lectura, y cachea la planilla', function () {
    app(NacionalVidaDriver::class)->verify($this->person, '1234567');
    app(NacionalVidaDriver::class)->verify($this->person, '7654321');

    $token = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), 'oauth2'))[0];
    [, $claims] = explode('.', $token->data()['assertion']);
    $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

    expect($claims)->toMatchArray(['iss' => 'crm@test.iam.gserviceaccount.com', 'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly'])
        ->and(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'sheets.googleapis.com'))->count())->toBe(1);

    $sheet = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), 'sheets.googleapis.com'))[0];
    expect($sheet->header('Authorization')[0])->toBe('Bearer tok-123');
});

it('una cédula con otro complemento solo matchea si no hay ambigüedad', function () {
    $this->sheets['sheet-nv'][] = ['8888888-1A', 'GEMELO UNO', 'X', 'VIGENTE'];
    $this->sheets['sheet-nv'][] = ['8888888-1B', 'GEMELO DOS', 'X', 'VIGENTE'];
    $source = app(GoogleSheetsInsuranceSource::class);

    expect($source->lookup('nacional_vida', '8888888-1B')['data'][0]['NOMBRE COMPLETO'])->toBe('GEMELO DOS')
        ->and($source->lookup('nacional_vida', '8888888')['success'])->toBeFalse();
});

it('si Google falla o falta la configuración, queda INDETERMINADO (no "no registrado")', function () {
    $this->googleDown = true;
    expect(app(NacionalVidaDriver::class)->verify($this->person, '1234567')['status'])->toBe('INDETERMINADO');

    config(['services.insurance_sheets.membresia.spreadsheet_id' => null]);
    expect(app(OdontokingMembershipDriver::class)->verify($this->person, '5555555')['status'])->toBe('INDETERMINADO');
});

it('Membresía: entiende todas las formas de la fecha del padrón real', function (string $observaciones, string $estado) {
    $this->sheets['sheet-mb'][] = ['4444444', 'Formato Fecha', '(SISTEMA 2024) '.$observaciones.' 7123456 70012345', 'Membresia Odontoking'];

    expect(app(OdontokingMembershipDriver::class)->verify($this->person, '4444444')['status'])->toBe($estado);
})->with([
    'MF HASTA EL vigente' => fn () => ['MF HASTA EL '.now()->addMonth()->format('d/m/Y'), 'VIGENTE'],
    'MF VENCE vencida'    => fn () => ['MF VENCE '.now()->subDay()->format('d/m/Y'), 'VENCIDO'],
    'MF VENCE EL vigente' => fn () => ['MF VENCE EL '.now()->addYear()->format('d/m/Y'), 'VIGENTE'],
    'día de un dígito'    => fn () => ['MF VENCE '.now()->subYear()->startOfMonth()->format('j/m/Y'), 'VENCIDO'],
    'vence hoy: vale hoy' => fn () => ['MF VENCE '.now()->format('d/m/Y'), 'VIGENTE'],
]);
