<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Webkul\User\Models\User;

/*
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 * Cada caso crea su propia ciudad (pipeline) y su sucursal con un sufijo único,
 * y nunca afirma totales de toda la tabla: las 8 sucursales reales están ahí.
 */
uses(DatabaseTransactions::class);

function agenteSucursalesLogin(): void
{
    Sanctum::actingAs(User::create([
        'name'     => 'Agente test '.uniqid(),
        'email'    => 'agente-'.uniqid().'@example.com',
        'password' => bcrypt('secret'),
        'status'   => 1,
        'role_id'  => DB::table('roles')->value('id'),
    ]));
}

function crearCiudad(string $nombre): void
{
    DB::table('lead_pipelines')->insert([
        'name'        => $nombre,
        'is_default'  => 0,
        'rotten_days' => 30,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

function crearSucursal(string $nombre, string $address, array $numeros = ['67400062'], string $city = ''): int
{
    return DB::table('warehouses')->insertGetId([
        'name'            => $nombre,
        'description'     => '',
        'contact_name'    => 'Encargado test',
        'contact_emails'  => json_encode([['label' => 'work', 'value' => 'test@example.com']]),
        'contact_numbers' => json_encode(array_map(fn ($n) => ['label' => 'work', 'value' => $n], $numeros)),
        'contact_address' => json_encode([
            'city'     => $city,
            'state'    => '',
            'address'  => $address,
            'country'  => 'BO',
            'postcode' => '0000',
        ]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

function sucursales(array $query = []): array
{
    return test()->getJson(route('api.kohlberg.sucursales', $query))
        ->assertOk()
        ->json('sucursales');
}

const TEXTO_TARIJA = "Horarios de atención:\r\nLunes a Viernes:\r\n8:30hrs - 12:30hrs  / 15:30hrs - 19:30hrs \r\nSábado: \r\n9:00hrs - 15:00hrs \r\n\r\nCalle 15 de Abril #275 casi esquina Méndez\r\nhttps://maps.app.goo.gl/TdwB6KnLV1Cyknbw5?g_st=iw";

it('pide autenticación', function () {
    test()->getJson(route('api.kohlberg.sucursales'))->assertUnauthorized();
});

it('devuelve solo los 5 campos, con la ciudad del pipeline', function () {
    agenteSucursalesLogin();
    $sufijo = uniqid();
    crearCiudad("Villa Tést {$sufijo}");
    crearSucursal("VILLA TEST {$sufijo}", TEXTO_TARIJA);

    test()->getJson(route('api.kohlberg.sucursales', ['ciudad' => "  villa test {$sufijo} "]))
        ->assertOk()
        ->assertExactJson([
            'sucursales' => [[
                'ciudad'    => "Villa Tést {$sufijo}",
                'direccion' => 'Calle 15 de Abril #275 casi esquina Méndez',
                'horarios'  => 'Lunes a Viernes: 8:30hrs - 12:30hrs / 15:30hrs - 19:30hrs · Sábado: 9:00hrs - 15:00hrs',
                'mapa_url'  => 'https://maps.app.goo.gl/TdwB6KnLV1Cyknbw5?g_st=iw',
                'telefono'  => '+591 67400062',
            ]],
        ]);
});

it('empareja por contact_address.city cuando el nombre no coincide', function () {
    agenteSucursalesLogin();
    $sufijo = uniqid();
    crearCiudad("Ciudad {$sufijo}");
    crearSucursal("Agencia central {$sufijo}", 'Av. Test 1', city: "Ciudad {$sufijo}");

    expect(sucursales(['ciudad' => "Ciudad {$sufijo}"]))
        ->toHaveCount(1)
        ->{0}->ciudad->toBe("Ciudad {$sufijo}");
});

it('devuelve lista vacía para una ciudad sin sucursal o inexistente', function (string $ciudad) {
    agenteSucursalesLogin();

    test()->getJson(route('api.kohlberg.sucursales', ['ciudad' => $ciudad]))
        ->assertOk()
        ->assertExactJson(['sucursales' => []]);
})->with(['Sin ciudad', 'Narnia']);

it('sin ciudad devuelve todas las emparejadas y nunca una sucursal sin pipeline', function () {
    agenteSucursalesLogin();
    $sufijo = uniqid();
    crearCiudad("Ciudad {$sufijo}");
    crearSucursal("Ciudad {$sufijo}", "Calle con ciudad {$sufijo}");
    // Como "Todas": existe en warehouses pero no es ninguna ciudad del CRM.
    crearSucursal("Comodín {$sufijo}", "Calle sin ciudad {$sufijo}");

    $direcciones = array_column(sucursales(), 'direccion');

    expect($direcciones)
        ->toContain("Calle con ciudad {$sufijo}")
        ->not->toContain("Calle sin ciudad {$sufijo}");
});

it('no omite una sucursal incompleta: los campos vacíos van en null', function () {
    agenteSucursalesLogin();
    $sufijo = uniqid();
    crearCiudad("Ciudad {$sufijo}");
    // El caso Cochabamba 22/09–16/10: horarios y mapa, sin calle; sin teléfono.
    crearSucursal("Ciudad {$sufijo}", "Horarios de atención\r\nSábado de 9:00hrs a 13:00hrs\r\nhttps://maps.app.goo.gl/abc", ['']);

    expect(sucursales(['ciudad' => "Ciudad {$sufijo}"]))->toBe([[
        'ciudad'    => "Ciudad {$sufijo}",
        'direccion' => null,
        'horarios'  => 'Sábado de 9:00hrs a 13:00hrs',
        'mapa_url'  => 'https://maps.app.goo.gl/abc',
        'telefono'  => null,
    ]]);
});

it('normaliza el teléfono a +591 sin duplicar el prefijo', function (string $numero, string $esperado) {
    agenteSucursalesLogin();
    $sufijo = uniqid();
    crearCiudad("Ciudad {$sufijo}");
    crearSucursal("Ciudad {$sufijo}", 'Av. Test 1', ['', $numero]);

    expect(sucursales(['ciudad' => "Ciudad {$sufijo}"])[0]['telefono'])->toBe($esperado);
})->with([
    'sin prefijo'       => ['67400062', '+591 67400062'],
    'con +591'          => ['+591 67400062', '+591 67400062'],
    'con 591 sin +'     => ['59167400062', '+591 67400062'],
    'con espacios'      => [' 674 000 62 ', '+591 67400062'],
]);

it('no escribe nada en la base', function () {
    agenteSucursalesLogin();
    $antes = DB::table('warehouses')->max('updated_at').'|'.DB::table('warehouses')->count();

    sucursales();
    sucursales(['ciudad' => 'Cochabamba']);

    expect(DB::table('warehouses')->max('updated_at').'|'.DB::table('warehouses')->count())->toBe($antes);
});
