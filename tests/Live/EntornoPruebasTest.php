<?php

/*
|--------------------------------------------------------------------------
| Chequeo del ENTORNO DE PRUEBAS (SMD gamma + número de WhatsApp de desarrollo)
|--------------------------------------------------------------------------
|
| Responde rápido "¿a qué está conectado el CRM y es coherente?":
|   - la BD, SMD, Dropbox y WhatsApp apuntan a desarrollo (no a producción);
|   - los doctores y etapas de la BD coinciden con lo que devuelve SMD gamma;
|   - el número de WhatsApp manda su webhook a este CRM y los Flows están
|     publicados en la misma cuenta, con el endpoint de este CRM y nuestra clave.
|
| Todo es SOLO LECTURA. No forma parte de la suite normal; se corre con:
|
|     php artisan integraciones:check            # este archivo + SMD/Dropbox live
|
| Sin ENTORNO_PRUEBAS_TESTS=1 todo se saltea.
|
| Los ids de producción salen de los bloques comentados del .env; si cambian,
| se pisan por env (ENTORNO_PROD_*), igual que la BD esperada.
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\ShareMeDataService;

beforeEach(function () {
    if (getenv('ENTORNO_PRUEBAS_TESTS') !== '1') {
        $this->markTestSkipped('Chequeo de entorno desactivado: usar `php artisan integraciones:check`.');
    }

    // TestCase bloquea los requests salientes; este chequeo necesita salir.
    Http::allowStrayRequests();

    $this->prod = [
        'db'              => getenv('ENTORNO_PROD_DB') ?: 'odontoking',
        'smd_host'        => getenv('ENTORNO_PROD_SMD_HOST') ?: 'app.sharemedata.com',
        'dropbox_folder'  => getenv('ENTORNO_PROD_DROPBOX_FOLDER') ?: '/smd-events/60ccc528c1c48400065e3861',
        'phone_number_id' => getenv('ENTORNO_PROD_PHONE_NUMBER_ID') ?: '117998067938203',
    ];

    $this->expectedDb = getenv('ENTORNO_DB') ?: 'odontoking_dev';
    $this->appUrl = rtrim((string) config('app.url'), '/');
});

/** GET a la Graph API con el token del .env; falla con el error de Meta legible. */
function graph(string $path, array $query = []): array
{
    $res = Http::timeout(20)
        ->withToken((string) config('whatsapp.cloud.token'))
        ->get(sprintf('https://graph.facebook.com/%s/%s', config('whatsapp.cloud.api_version', 'v21.0'), ltrim($path, '/')), $query);

    expect($res->successful())->toBeTrue("Meta respondió {$res->status()} en {$path}: ".($res->json('error.message') ?? $res->body()));

    return $res->json();
}

/** Médicos que SMD ofrece en los próximos días, por especialidad: [_id => "Nombre (Especialidad)"]. */
function smdPhysicians(ShareMeDataService $smd): array
{
    $tz = config('app.timezone', 'America/La_Paz');
    $from = now($tz)->addDay()->startOfDay();
    $to = $from->copy()->addDays(6)->endOfDay();
    $physicians = [];

    foreach (array_filter((array) $smd->getSpecialties(), 'is_string') as $specialty) {
        $rows = $smd->availabilityBySpecialty($specialty, (string) config('smd.default_subsidiary', 'Santa Cruz'), $from->toIso8601String(), $to->toIso8601String());

        foreach ((array) $rows as $row) {
            $p = $row['physician'] ?? null;

            if (! empty($p['_id']) && empty($p['archived'])) {
                $physicians[$p['_id']] = trim(($p['name'] ?? '').' '.($p['lastName'] ?? ''))." ({$specialty})";
            }
        }
    }

    return $physicians;
}

/*
|--------------------------------------------------------------------------
| 1. Configuración: ¿todo apunta a desarrollo?
|--------------------------------------------------------------------------
*/

describe('configuración apunta a desarrollo', function () {
    it('la BD activa es la de pruebas', function () {
        $db = DB::connection()->getDatabaseName();

        expect($db)->not->toBe($this->prod['db'], 'El CRM está escribiendo en la BD de PRODUCCIÓN')
            ->and($db)->toBe($this->expectedDb, "BD activa: {$db}; se esperaba {$this->expectedDb} (pisar con ENTORNO_DB)");
    });

    it('SMD apunta a gamma, no a producción', function () {
        foreach (['base_url', 'patients_url'] as $key) {
            expect((string) config("services.sharemedata.{$key}"))
                ->not->toContain($this->prod['smd_host'], "services.sharemedata.{$key} apunta a SMD de producción");
        }
    });

    it('Dropbox lee la carpeta de la clínica de pruebas', function () {
        expect(rtrim((string) config('smd.dropbox.appointments_folder'), '/'))
            ->not->toBe($this->prod['dropbox_folder'], 'DROPBOX_APPOINTMENTS_FOLDER es la carpeta de producción');
    });

    it('el número de WhatsApp no es el de producción', function () {
        expect((string) config('whatsapp.cloud.phone_number_id'))
            ->not->toBe($this->prod['phone_number_id'], 'WHATSAPP_PHONE_NUMBER_ID es el número de producción');
    });

    it('no hay config cacheada que tape el .env', function () {
        // Con config:cache el .env editado no se lee hasta volver a cachear.
        expect(file_exists(base_path('bootstrap/cache/config.php')))
            ->toBeFalse('Existe bootstrap/cache/config.php: correr `php artisan config:clear` en el contenedor');
    });
});

/*
|--------------------------------------------------------------------------
| 2. BD de pruebas coherente con la configuración
|--------------------------------------------------------------------------
*/

describe('BD de pruebas coherente', function () {
    it('todas las etapas SMD_STAGE_* y la de Recepción existen en la BD', function () {
        $stages = array_filter([
            ...config('smd.stage_map'),
            'recepcion' => env('WHATSAPP_CRM_STAGE_RECEPCION'),
        ]);

        $existing = DB::table('lead_pipeline_stages')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_filter($stages, fn ($id) => ! in_array((int) $id, $existing, true));

        expect($missing)->toBeEmpty('Etapas configuradas que no existen en esta BD: '.json_encode($missing).' (¿se dejó el bloque DEV/PROD equivocado de SMD_STAGE_*?)');
    });

    it('ningún id de SMD está repetido en dos doctores', function () {
        // Con duplicados, las citas pueden quedar en la ficha vieja (inactiva) y
        // no verse en la agenda del doctor.
        $duplicados = DB::table('doctors')
            ->whereNotNull('unique_id')->where('unique_id', '!=', '')
            ->groupBy('unique_id')->havingRaw('count(*) > 1')
            ->selectRaw("unique_id, group_concat(concat(id, ' ', trim(replace(name, char(10), '')), if(is_active, '', ' (inactivo)')) separator ' / ') as doctores")
            ->pluck('doctores', 'unique_id')
            ->map(fn ($d, $uid) => "{$uid}: {$d}")
            ->values()
            ->all();

        expect($duplicados)->toBeEmpty("Doctores con el mismo id de SMD (unificar o vaciar el unique_id del viejo):\n".implode("\n", $duplicados));
    });

    it('SMD_LIVE_DOCTOR_ID existe y está vinculado a SMD', function () {
        $id = env('SMD_LIVE_DOCTOR_ID');

        if (! $id) {
            $this->markTestSkipped('SMD_LIVE_DOCTOR_ID no definido.');
        }

        $doctor = DB::table('doctors')->where('id', (int) $id)->first();

        expect($doctor)->not->toBeNull("SMD_LIVE_DOCTOR_ID={$id} no existe en esta BD")
            ->and((string) $doctor->unique_id)->toMatch('/^[0-9a-f]{24}$/', "El doctor {$id} no tiene un unique_id de SMD válido");
    });
});

/*
|--------------------------------------------------------------------------
| 3. SMD gamma ↔ doctores del CRM
|--------------------------------------------------------------------------
*/

describe('SMD gamma ↔ doctores', function () {
    it('cada médico que SMD ofrece existe ACTIVO en el CRM', function () {
        $physicians = smdPhysicians(app(ShareMeDataService::class));

        expect($physicians)->not->toBeEmpty('SMD gamma no devolvió ningún médico con agenda en 7 días: ¿API key o caída?');

        $active = DB::table('doctors')->where('is_active', 1)->pluck('unique_id')->all();
        $missing = array_diff_key($physicians, array_flip($active));

        // Si falta, las citas de ese médico entran al CRM sin doctor y el Flow no lo ofrece.
        expect($missing)->toBeEmpty("Médicos con agenda en SMD sin doctor activo en el CRM:\n".implode("\n", $missing));
    });

    it('los doctores activos del CRM tienen al menos una especialidad', function () {
        // Sin especialidad, el Flow de agenda nunca los muestra.
        $sinEspecialidad = DB::table('doctors as d')
            ->leftJoin('doctor_specialty as ds', 'ds.doctor_id', '=', 'd.id')
            ->where('d.is_active', 1)
            ->whereNull('ds.doctor_id')
            ->pluck('d.name')
            ->map(fn ($n) => trim($n))
            ->all();

        expect($sinEspecialidad)->toBeEmpty('Doctores activos sin especialidad: '.implode(', ', $sinEspecialidad));
    });

    it('la carpeta de Dropbox de pruebas recibe eventos', function () {
        $dropbox = app(DropboxService::class);
        $files = [];

        for ($i = 0; $i < 7 && ! $files; $i++) {
            $files = $dropbox->listFilesForDate(now()->subDays($i)->toDateString());
        }

        expect($files)->not->toBeEmpty('Sin eventos de SMD en Dropbox en 7 días para '.config('smd.dropbox.appointments_folder'));
    });
});

/*
|--------------------------------------------------------------------------
| 4. WhatsApp: número, webhook y Flows
|--------------------------------------------------------------------------
*/

describe('WhatsApp número de pruebas', function () {
    it('el número está conectado y su webhook llega a este CRM', function () {
        $phone = graph((string) config('whatsapp.cloud.phone_number_id'), ['fields' => 'display_phone_number,status,webhook_configuration']);
        $expected = "{$this->appUrl}/api/v1/whatsapp/webhook";

        expect($phone['status'] ?? null)->toBe('CONNECTED', "El número {$phone['display_phone_number']} no está CONNECTED");

        // Un override por número gana sobre el de la app.
        $webhook = $phone['webhook_configuration']['phone_number'] ?? $phone['webhook_configuration']['application'] ?? null;
        expect($webhook)->toBe($expected, "Los mensajes al {$phone['display_phone_number']} van a {$webhook}, no a este CRM");
    });

    it('el número pertenece a WHATSAPP_WABA_ID y la cuenta está suscrita a la app', function () {
        $waba = (string) config('whatsapp.cloud.waba_id');
        $numbers = collect(graph("{$waba}/phone_numbers", ['fields' => 'id'])['data'] ?? [])->pluck('id')->all();

        expect(in_array((string) config('whatsapp.cloud.phone_number_id'), $numbers, true))
            ->toBeTrue("El número no está en la WABA {$waba} (tiene: ".implode(', ', $numbers).'): los templates y Flows se buscarían en otra cuenta');

        // Sin suscripción Meta no manda ningún webhook de esta cuenta.
        expect(graph("{$waba}/subscribed_apps")['data'] ?? [])->not->toBeEmpty("La WABA {$waba} no tiene ninguna app suscrita: no llegan mensajes");
    });

    it('la plantilla existe y está aprobada en la cuenta', function (string $template) {
        $config = config("whatsapp.gateways.cloud_api.templates.{$template}");

        $templates = graph(config('whatsapp.cloud.waba_id').'/message_templates', ['name' => $config['name'], 'fields' => 'name,status,language'])['data'] ?? [];
        $approved = collect($templates)->where('name', $config['name'])->where('status', 'APPROVED')->where('language', $config['language']);

        expect($approved->isNotEmpty())
            ->toBeTrue("La plantilla {$config['name']} ({$config['language']}) no está APPROVED en esta WABA (¿es la de producción?)");
    })->with(['appointment_reminder', 'doctor_shift_reminder']);
});

describe('WhatsApp Flows', function () {
    it('cada Flow configurado está publicado en esta WABA y llama a este CRM', function (string $flow) {
        $id = config("whatsapp.flows.{$flow}.id");

        if (! $id) {
            $this->markTestSkipped('WHATSAPP_FLOW_'.strtoupper($flow).'_ID no definido.');
        }

        $data = graph((string) $id, ['fields' => 'name,status,endpoint_uri,validation_errors,whatsapp_business_account']);
        $mode = config("whatsapp.flows.{$flow}.mode", 'published');

        expect($data['whatsapp_business_account']['id'] ?? null)
            ->toBe((string) config('whatsapp.cloud.waba_id'), "El Flow {$data['name']} es de otra WABA: el número de pruebas no puede enviarlo");

        expect($data['endpoint_uri'] ?? null)
            ->toBe("{$this->appUrl}/api/v1/whatsapp/flows/endpoint", "El Flow {$data['name']} pide datos a otro servidor");

        expect($data['validation_errors'] ?? [])->toBeEmpty("El Flow {$data['name']} tiene errores de validación");

        if ($mode === 'published') {
            expect($data['status'] ?? null)->toBe('PUBLISHED', "El Flow {$data['name']} está {$data['status']} pero se envía en modo published");
        }
    })->with(['patient', 'cancel', 'reschedule']);

    it('la clave pública subida a Meta es la de nuestra clave privada', function () {
        $path = (string) config('whatsapp.flows.private_key_path');

        expect(is_readable($path))->toBeTrue("No se puede leer la clave privada de Flows en {$path}");

        $key = openssl_pkey_get_private((string) file_get_contents($path), config('whatsapp.flows.private_key_passphrase') ?? '');
        expect($key)->not->toBeFalse('La clave privada de Flows no abre (¿passphrase?)');

        // La clave de cifrado es POR NÚMERO: al cambiar de número hay que re-subirla.
        $meta = graph(config('whatsapp.cloud.phone_number_id').'/whatsapp_business_encryption')['data'][0] ?? [];
        $normalize = fn (?string $pem) => preg_replace('/\s+|-----[A-Z ]+-----/', '', (string) $pem);

        expect($meta['business_public_key_signature_status'] ?? null)->toBe('VALID', 'La clave pública en Meta no está VALID: `php artisan whatsapp:flows:keys --upload`');
        expect($normalize($meta['business_public_key'] ?? null))
            ->toBe($normalize(openssl_pkey_get_details($key)['key']), 'Meta tiene otra clave pública para este número: los Flows no se van a poder descifrar. `php artisan whatsapp:flows:keys --upload`');
    });

    it('el endpoint de Flows responde el health check cifrado de Meta', function () {
        // Sin firma ni cifrado válidos tiene que rechazar, nunca 5xx: un 500 acá
        // significa que la clave no se puede leer desde php-fpm (permisos www-data).
        $res = Http::timeout(15)->acceptJson()->post("{$this->appUrl}/api/v1/whatsapp/flows/endpoint", []);

        expect($res->status())->toBeLessThan(500, "El endpoint de Flows devolvió {$res->status()}: revisar el log del CRM");
    });
});
