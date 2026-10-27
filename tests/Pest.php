<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
 */

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\DatabaseTransactions::class)->in('Feature');

// Pruebas live contra SMD/Dropbox de producción: solo vía `php artisan smd:test-live`.
uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\DatabaseTransactions::class)->in('Live');

// Sin DatabaseTransactions a propósito: lo que crea tiene que quedar en la BD.
uses(\Tests\TestCase::class)->in('LiveBooking');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
 */

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
 */

/**
 * Get default admin which is created on fresh instance.
 *
 * @return \Webkul\User\Models\User
 */
function getDefaultAdmin()
{
    $admin = \Webkul\User\Models\User::find(1);

    return $admin;
}

/**
 * Sanctum authenticated admin.
 *
 * @return \Webkul\User\Models\User
 */
function actingAsSanctumAuthenticatedAdmin()
{
    return \Laravel\Sanctum\Sanctum::actingAs(
        getDefaultAdmin(),
        ['*']
    );
}

/**
 * Get first name.
 *
 * @param  string  $fullName
 * @return string
 */
function getFirstName($fullName)
{
    return explode(' ', $fullName)[0];
}

/*
|--------------------------------------------------------------------------
| Helpers de sincronización SMD / Dropbox
|--------------------------------------------------------------------------
|
| Compartidos por los tests de sync (SmdStalePayloadSyncTest, SmdStatusTagSyncTest).
| Viven acá y no en un archivo de test porque Pest carga los archivos a medida
| que los corre: definidos en uno, correr el otro por separado explotaba.
|
 */

/**
 * Payload con la forma real de SMD (ver planning/10-actualizacion-eventstatus-dropbox).
 * `$updatedAt` a null simula los archivos anteriores al cambio, que no traían el campo.
 */
function smdEvent(string $id, array $overrides = []): array
{
    return array_merge([
        '_id'         => $id,
        'eventId'     => '1783952187466',
        'archived'    => false,
        'status'      => '',
        'startDate'   => '2026-07-21T13:00:00.000Z',
        'endDate'     => '2026-07-21T14:00:00.000Z',
        'type'        => 'event',
        'summary'     => 'Creado 21 de Jul',
        'description' => '',
        'created_at'  => '2026-07-13T14:16:50.325Z',
        'updated_at'  => '2026-07-13T14:16:50.325Z',
        '__v'         => 0,
        'attendances' => [[
            '_id'      => 'pat-'.$id,
            'name'     => 'Juan',
            'lastName' => 'Perez',
            'fullName' => 'Juan Perez',
            'type'     => 'patient',
            'phone'    => '7'.rand(1000000, 9999999),
        ]],
        'owner' => [
            '_id'      => 'doc-'.$id,
            'name'     => 'Dr. Sync',
            'lastName' => 'Test',
            'phone'    => '',
        ],
    ], $overrides);
}

/**
 * Entry de Dropbox. `$contentHash` es la huella del contenido que devuelve el
 * listado: es lo que distingue "misma ruta, mismo archivo" de "misma ruta,
 * archivo reescrito", así que los fixtures la traen como en la realidad.
 */
function fileEntry(string $date, string $hhmm, string $id, ?string $contentHash = null): array
{
    $name = "event-{$date}-{$hhmm}-{$id}.json";
    $path = "/smd-events/{$date}/{$name}";

    return [
        '.tag'         => 'file',
        'name'         => $name,
        'path_lower'   => $path,
        'content_hash' => $contentHash ?? hash('sha256', $path),
    ];
}

/**
 * Simula Dropbox: `$folders` mapea fecha => entries, `$payloads` mapea path => json.
 */
function fakeDropbox(array $folders, array $payloads): void
{
    // Http::fake() acumula stubs en vez de reemplazarlos, y el Factory está
    // bindeado como singleton: sin descartarlo, una segunda llamada en el mismo
    // test no tendría efecto porque ganaría el stub anterior.
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();

    Http::fake(function ($request) use ($folders, $payloads) {
        $url = $request->url();

        if (str_contains($url, 'oauth2/token')) {
            return Http::response(['access_token' => 'tok'], 200);
        }

        if (str_contains($url, 'files/list_folder')) {
            $path = $request->data()['path'] ?? '';
            $date = basename($path);
            $entries = $folders[$date] ?? [];

            return Http::response(['entries' => $entries, 'cursor' => 'c', 'has_more' => false], 200);
        }

        if (str_contains($url, 'files/download')) {
            $arg = json_decode($request->header('Dropbox-API-Arg')[0] ?? '{}', true);

            return Http::response(json_encode($payloads[$arg['path'] ?? ''] ?? []), 200);
        }

        return Http::response([], 200);
    });
}

function activityFor(string $externalId): ?object
{
    $row = DB::table('smd_synced_events')->where('external_id', $externalId)->first();

    return $row ? DB::table('activities')->find($row->activity_id) : null;
}
