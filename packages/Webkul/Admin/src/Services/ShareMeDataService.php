<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\Smd\ApiBudget;
use Webkul\Doctor\Repositories\SpecialtyRepository;

class ShareMeDataService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $patientsBaseUrl;

    protected $lastResponse = null;

    public function __construct(
        protected SpecialtyRepository $specialtyRepository
    ) {
        $this->apiKey = config('services.sharemedata.api_key');
        $this->baseUrl = config('services.sharemedata.base_url', 'https://gamma.sharemedata.com/api/calendar');
        $this->patientsBaseUrl = config('services.sharemedata.patients_url', 'https://gamma.sharemedata.com/api');
    }

    /**
     * Cliente para UNA consulta a SMD. Toda consulta pasa por acá y se cuenta
     * contra la cuota contratada (ApiBudget): quién la pidió y con qué método.
     */
    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $endpoint = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'http';
        // Pasado el tope diario, una consulta no esencial lanza QuotaExceeded
        // acá: cada método la atrapa como una falla de SMD y responde vacío.
        app(ApiBudget::class)->authorize($endpoint, ApiBudget::callerOutside(self::class));

        $client = Http::withHeaders([
            'Accept'  => 'application/json',
            'apikey'  => $this->apiKey,
        ]);

        if (app()->environment(['local', 'testing'])) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Obtener especialidades del sistema externo
     */
    public function getSpecialties()
    {
        try {
            $this->lastResponse = null;
            $response = $this->http()->get("{$this->baseUrl}/specialties");

            $this->lastResponse = [
                'status' => $response->status(),
                'body'   => $response->json() ?: $response->body(),
            ];

            if ($response->successful()) {
                return $response->json();
            }

            return [];
        } catch (\Exception $e) {
            Log::error('ShareMeData: Excepción obteniendo especialidades: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Sincronizar especialidades con el sistema externo
     */
    public function syncSpecialties()
    {
        $specialties = $this->getSpecialties();
        Log::debug('[SMD-DEBUG] syncSpecialties respuesta cruda', [
            'specialties' => $specialties,
        ]);
        $syncedCount = 0;
        $alreadyExistCount = 0;

        foreach ($specialties as $name) {
            $slug = \Illuminate\Support\Str::slug($name);
            $exists = $this->specialtyRepository->findOneWhere(['slug' => $slug]);

            if ($exists) {
                $alreadyExistCount++;
            } else {
                $this->specialtyRepository->fetchOrCreateByName($name);
                $syncedCount++;
            }
        }

        return [
            'synced'  => $syncedCount,
            'existed' => $alreadyExistCount,
            'total'   => count($specialties),
        ];
    }

    /**
     * Verificar disponibilidad de un doctor por especialidad y sucursal
     */
    public function checkAvailability($doctorExternalId, $specialty, $subsidiary, $from, $to)
    {
        try {
            $this->lastResponse = null;
            $appTz = config('app.timezone', 'America/La_Paz');
            $fromFormatted = Carbon::parse($from)->setTimezone($appTz)->format('Y-m-d\TH:i:sP');
            $toFormatted = Carbon::parse($to)->setTimezone($appTz)->format('Y-m-d\TH:i:sP');

            $queryParams = [
                'where'      => 'subsidiary='.urlencode($subsidiary).'&specialty='.urlencode($specialty),
                'from'       => $fromFormatted,
                'to'         => $toFormatted,
                'groupByDay' => 'true',
                'timeZone'   => 'America/La_Paz',
            ];

            $response = $this->http()->get("{$this->baseUrl}/schedule/availability", $queryParams);

            $this->lastResponse = [
                'status' => $response->status(),
                'body'   => $response->json() ?: $response->body(),
            ];

            if ($response->successful()) {
                $data = $response->json();

                Log::debug('[SMD-DEBUG] checkAvailability respuesta cruda', [
                    'doctor_external_id' => $doctorExternalId,
                    'specialty'          => $specialty,
                    'from'               => $fromFormatted,
                    'to'                 => $toFormatted,
                    'physicians_found'   => collect($data)->pluck('physician')->map(fn ($p) => [
                        '_id'  => $p['_id'] ?? null,
                        'name' => ($p['name'] ?? '').' '.($p['lastName'] ?? ''),
                    ])->toArray(),
                ]);

                foreach ($data as $item) {
                    if (isset($item['physician']['_id']) && $item['physician']['_id'] == $doctorExternalId) {
                        Log::debug('[SMD-DEBUG] Physician encontrado, slots devueltos', [
                            'doctor_external_id' => $doctorExternalId,
                            'slots_count'        => count($item['slots'] ?? []),
                            'slots_sample'       => array_slice((array) ($item['slots'] ?? []), 0, 2),
                        ]);

                        return $item['slots'] ?? [];
                    }
                }
            }

            return [];
        } catch (\Exception $e) {
            Log::error('ShareMeData: Error verificando disponibilidad: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Obtener la última respuesta en crudo
     */
    /**
     * Disponibilidad de TODOS los doctores de una especialidad en una sola
     * consulta: SMD filtra por especialidad y sucursal, no por doctor.
     * (checkAvailability pide lo mismo y se queda con un doctor.)
     *
     * @return array<int, array{physician: array, slots: array}>|null null = SMD no respondió bien
     */
    /** La última availabilityBySpecialty() se cortó por tiempo (no es que SMD falló). */
    public bool $lastTimedOut = false;

    public function availabilityBySpecialty(string $specialty, string $subsidiary, string $from, string $to, ?int $timeout = null): ?array
    {
        $this->lastTimedOut = false;

        try {
            $tz = config('app.timezone', 'America/La_Paz');

            $response = $this->http()->timeout($timeout ?? (int) config('smd.availability_timeout', 30))->get("{$this->baseUrl}/schedule/availability", [
                'where'      => 'subsidiary='.urlencode($subsidiary).'&specialty='.urlencode($specialty),
                'from'       => Carbon::parse($from)->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
                'to'         => Carbon::parse($to)->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
                'groupByDay' => 'true',
                'timeZone'   => 'America/La_Paz',
            ]);

            return $response->successful() && is_array($response->json()) ? $response->json() : null;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->lastTimedOut = true;
            Log::info('[SMD] availabilityBySpecialty: sin respuesta dentro del tiempo', ['specialty' => $specialty, 'timeout' => $timeout]);

            return null;
        } catch (\Exception $e) {
            Log::warning('[SMD] availabilityBySpecialty: '.$e->getMessage(), ['specialty' => $specialty]);

            return null;
        }
    }

    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * Buscar paciente en SMD por teléfono. Retorna array de pacientes encontrados
     * ([] también si SMD falló; para distinguir, usar searchPatientOrNull()).
     * La API de SMD requiere búsqueda regex: where=phone=/numero/
     */
    public function searchPatient(string $phone): array
    {
        return $this->searchPatientOrNull($phone) ?? [];
    }

    /**
     * Igual que searchPatient(), pero devuelve null si SMD no respondió bien.
     *
     * searchPatient() convierte un fallo en [], y quien llama no puede saber si
     * el paciente no existe o si SMD estaba caído: un contacto nuevo de WhatsApp
     * quedaba registrado "sin datos de SMD" sin que nadie supiera por qué.
     */
    public function searchPatientOrNull(string $phone): ?array
    {
        try {
            $response = $this->http()->get("{$this->patientsBaseUrl}/patients", [
                'where'      => 'phone=/'.urlencode($phone).'/',
                'select'     => '_id,fullName,phone,secondEmail,name,lastName,personID,birthday,extra',
                'pageSize'   => 20,
                'pageNumber' => 1,
            ]);

            if ($response->successful()) {
                return $response->json('data') ?? $response->json() ?? [];
            }

            Log::warning('[SMD] searchPatient falló', [
                'phone_suffix' => '***'.substr($phone, -4),
                'status'       => $response->status(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('[SMD] searchPatient excepción: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Buscar paciente en SMD por CI (personID).
     */
    public function searchPatientByCi(string $ci): array
    {
        try {
            $response = $this->http()->get("{$this->patientsBaseUrl}/patients", [
                'where'      => 'personID=/'.urlencode($ci).'/',
                'select'     => '_id,fullName,phone,secondEmail,name,lastName,personID,birthday,extra',
                'pageSize'   => 5,
                'pageNumber' => 1,
            ]);

            return $response->successful()
                ? ($response->json('data') ?? $response->json() ?? [])
                : [];
        } catch (\Exception $e) {
            Log::error('[SMD] searchPatientByCi excepción: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Buscar paciente en SMD por email (secondEmail).
     */
    /**
     * Buscar paciente en SMD por nombre (regex sobre fullName).
     *
     * Hace falta porque el `_id` que trae el asistente del calendario NO siempre
     * resuelve en la API de pacientes: hay pacientes que existen en SMD y cuyo
     * `GET /patients/{_id}` viene vacío. Sin esta vía se los daba por
     * inexistentes y quedaban sin teléfono para siempre.
     *
     * El nombre se pasa por tokens y no entero: el CRM guarda "VACA AGUILERA,
     * CATALINA" y SMD "CATALINA VACA AGUILERA (SISTEMA 2020) AP COBERTURA...",
     * así que un regex sobre el nombre completo no coincidiría nunca.
     */
    public function searchPatientByName(string $name, int $limit = 20): array
    {
        $this->lastLookup = 'missing';

        // Sin acentos: SMD guarda "EMILIA MONTES SANCHEZ" y el CRM "Emilia Montes
        // Sánchez". Buscar "Sánchez" contra "SANCHEZ" no devuelve nada. El regex
        // de la API sí ignora mayúsculas por su cuenta.
        $sinAcentos = fn (string $t): string => iconv('UTF-8', 'ASCII//TRANSLIT', $t) ?: $t;

        $tokens = collect(preg_split('/[^\p{L}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($t) => preg_replace('/[^a-zA-Z]/', '', $sinAcentos($t)))
            ->filter(fn ($t) => mb_strlen($t) >= 3)
            ->take(3);

        if ($tokens->isEmpty()) {
            return [];
        }

        // Se filtra en SMD por UN token y el resto se confirma acá: un regex con
        // lookaheads para exigir todos a la vez no da resultados contra esta API.
        //
        // Se prueba token por token porque un apellido común desborda la página:
        // "Sanchez" devuelve más de `pageSize` fichas y la buscada queda afuera,
        // mientras que "Emilia" la encuentra de una. Se empieza por el más largo
        // -suele ser el más distintivo- y se sigue hasta confirmar.
        $confirma = function (array $patient) use ($tokens, $sinAcentos): bool {
            $full = mb_strtolower($sinAcentos($patient['fullName'] ?? ''));

            foreach ($tokens as $token) {
                if (! str_contains($full, mb_strtolower($token))) {
                    return false;
                }
            }

            return true;
        };

        foreach ($tokens->sortByDesc(fn ($t) => mb_strlen($t)) as $ancla) {
            try {
                $response = $this->http()->get("{$this->patientsBaseUrl}/patients", [
                    // Sin el flag `/i`: con él la API devuelve vacío siempre, sin
                    // error. Igual ignora mayúsculas por su cuenta.
                    'where'      => 'fullName=/'.urlencode($ancla).'/',
                    'select'     => '_id,fullName,phone,secondEmail,name,lastName,personID,birthday,extra',
                    'pageSize'   => $limit,
                    'pageNumber' => 1,
                ]);

                if (! $response->successful()) {
                    $this->lastLookup = 'error';
                    Log::warning('[SMD] searchPatientByName falló', [
                        'status' => $response->status(),
                        'token'  => $ancla,
                    ]);

                    continue;
                }

                $found = $response->json('data') ?? $response->json() ?? [];

                $confirmados = array_values(array_filter($found, $confirma));

                if ($confirmados) {
                    $this->lastLookup = 'found';

                    return $confirmados;
                }
            } catch (\Exception $e) {
                $this->lastLookup = 'error';
                Log::error('[SMD] searchPatientByName excepción: '.$e->getMessage());
            }
        }

        return [];
    }

    public function searchPatientByEmail(string $email): array
    {
        try {
            $response = $this->http()->get("{$this->patientsBaseUrl}/patients", [
                'where'      => 'secondEmail=/'.urlencode($email).'/',
                'select'     => '_id,fullName,phone,secondEmail,name,lastName,personID,birthday,extra',
                'pageSize'   => 5,
                'pageNumber' => 1,
            ]);

            if ($response->successful()) {
                return $response->json('data') ?? $response->json() ?? [];
            }

            return [];
        } catch (\Exception $e) {
            Log::error('[SMD] searchPatientByEmail excepción: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Crear paciente en SMD. Retorna ['success' => bool, 'data' => [...], 'duplicate' => bool].
     * Endpoint correcto: /patients/user (no /patients)
     * Maneja @error.duplicatePatient como duplicado no fatal.
     */
    public function createPatient(array $data): array
    {
        try {
            $payload = array_filter([
                'name'        => $data['first_name'] ?? null,
                'lastName'    => $data['last_name'] ?? null,
                'phone'       => $data['phone'] ?? null,
                'birthday'    => $data['birthday'] ?? null,
                'gender'      => $data['gender'] ?? null,
                'personID'    => $data['ci'] ?? null,
                'expedido'    => $data['expedido'] ?? null,
                'secondEmail' => $data['email'] ?? null,
                'nationality' => $data['nationality'] ?? 'BO',
                'note'        => $data['notes'] ?? null,
            ], fn ($v) => $v !== null);

            if (! empty($data['insurance'])) {
                $payload['extra'] = ['insurance' => [$data['insurance']]];
            }

            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->patientsBaseUrl}/patients/user", $payload);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            $body = $response->json();
            $message = $body['message'] ?? $response->body();

            $isDuplicate = str_contains((string) $message, '@error.duplicatePatient');

            Log::warning('[SMD] createPatient '.($isDuplicate ? 'duplicado' : 'falló'), [
                'status'  => $response->status(),
                'message' => $message,
            ]);

            return [
                'success'   => false,
                'duplicate' => $isDuplicate,
                'message'   => $message,
                'status'    => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('[SMD] createPatient excepción: '.$e->getMessage());

            return ['success' => false, 'duplicate' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtener un paciente de SMD por su ID interno (_id).
     * Retorna el array del paciente o null si no existe o hay error.
     */
    /**
     * Resultado de la última getPatient()/searchPatientByName(): 'found',
     * 'missing' (SMD respondió que no existe) o 'error' (no se pudo saber).
     * Solo un 'missing' se puede recordar: un 'error' hay que reintentarlo.
     */
    public ?string $lastLookup = null;

    public function getPatient(string $smdId): ?array
    {
        $this->lastLookup = 'error';

        try {
            $response = $this->http()->get("{$this->patientsBaseUrl}/patients/".rawurlencode($smdId), [
                'select' => '_id,fullName,phone,secondEmail,name,lastName,personID,birthday,extra',
            ]);

            if ($response->successful()) {
                $this->lastLookup = 'found';

                return $response->json();
            }

            // 404 / 400 "@error.usercantfound": la ficha no existe.
            if (in_array($response->status(), [400, 404], true)) {
                $this->lastLookup = 'missing';
            }

            Log::warning('[SMD] getPatient falló', [
                'smd_id' => $smdId,
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('[SMD] getPatient excepción: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Varias fichas de paciente a la vez.
     *
     * `getPatient()` de a uno era el cuello de botella de la carga masiva: ~0,9 s
     * por llamada, en serie, una por paciente nuevo. El tiempo es latencia pura,
     * así que pedirlas en paralelo es todo lo que hace falta.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array|null> id => ficha (null si no existe)
     */
    public function getPatientsMany(array $ids, int $concurrency = 10): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if (! $ids) {
            return [];
        }

        $select = '_id,fullName,phone,secondEmail,name,lastName,personID,birthday,extra';
        $result = [];

        foreach (array_chunk($ids, max(1, $concurrency)) as $chunk) {
            // En paralelo no pasa por http(): se cuenta acá, una por ficha.
            try {
                app(ApiBudget::class)->authorize('getPatientsMany', ApiBudget::callerOutside(self::class), count($chunk));

                $responses = Http::pool(fn ($pool) => array_map(
                    fn ($id) => $pool->as($id)
                        ->withHeaders(['Accept' => 'application/json', 'apikey' => $this->apiKey])
                        ->get("{$this->patientsBaseUrl}/patients/".rawurlencode((string) $id), ['select' => $select]),
                    $chunk,
                ));
            } catch (\Exception $e) {
                Log::error('[SMD] getPatientsMany excepción: '.$e->getMessage());

                continue;
            }

            foreach ($chunk as $id) {
                $response = $responses[$id] ?? null;

                // null = no existe. Se guarda igual para no volver a preguntarlo.
                $result[$id] = ($response instanceof \Illuminate\Http\Client\Response && $response->successful())
                    ? $response->json()
                    : null;
            }
        }

        return $result;
    }

    /**
     * Actualizar paciente en SMD por ID. Usa PATCH (no PUT).
     */
    public function updatePatient(string $smdPatientId, array $data): array
    {
        try {
            $payload = array_filter([
                'name'        => $data['first_name'] ?? null,
                'lastName'    => $data['last_name'] ?? null,
                'phone'       => $data['phone'] ?? null,
                'birthday'    => $data['birthday'] ?? null,
                'personID'    => $data['ci'] ?? null,
                'secondEmail' => $data['email'] ?? null,
                'note'        => $data['notes'] ?? null,
            ], fn ($v) => $v !== null);

            if (! empty($data['insurance'])) {
                $payload['extra'] = ['insurance' => [$data['insurance']]];
            }

            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->patch("{$this->patientsBaseUrl}/patients/".rawurlencode($smdPatientId), $payload);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            $body = $response->json() ?? [];
            // SMD usa 'error' o 'message' indistintamente según el endpoint
            $message = $body['message'] ?? $body['error'] ?? $response->body();

            $notFound = str_contains((string) $message, '@error.usercantfound')
                || str_contains((string) $message, 'notfound')
                || $response->status() === 404;

            Log::warning('[SMD] updatePatient falló', [
                'smd_patient_id' => $smdPatientId,
                'status'         => $response->status(),
                'message'        => $message,
                'not_found'      => $notFound,
            ]);

            return [
                'success'   => false,
                'not_found' => $notFound,
                'message'   => $message,
                'status'    => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('[SMD] updatePatient excepción: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Crear evento en el sistema externo
     */
    public function createEvent($data)
    {
        try {
            $this->lastResponse = null;
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->timeout(30)
                ->post("{$this->baseUrl}/schedule/createEvent", $data);

            $this->lastResponse = [
                'status' => $response->status(),
                'body'   => $response->json() ?: $response->body(),
            ];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?: $response->body(),
                'status'  => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('ShareMeData: Excepción creando evento: '.$e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Editar un evento existente en SMD. PATCH /calendar/events/{_id}.
     *
     * A diferencia de createEvent (que usa /schedule/createEvent con un formato
     * simplificado), la edición usa el formato COMPLETO del evento: attendances
     * como array con `type`, startDate/endDate y status. Es el mismo formato que
     * lee la sync, así que el cambio vuelve limpio en la próxima corrida.
     *
     * @param  string  $smdEventId  El `_id` del evento en SMD (external_id local).
     */
    public function updateEvent(string $smdEventId, array $data): array
    {
        try {
            $this->lastResponse = null;

            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->timeout(30)
                ->patch("{$this->baseUrl}/events/{$smdEventId}", $data);

            $this->lastResponse = [
                'status' => $response->status(),
                'body'   => $response->json() ?: $response->body(),
            ];

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::warning('[SMD] updateEvent falló', [
                'event_id' => $smdEventId,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => $response->json('message') ?: $response->body(),
                'status'  => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('[SMD] updateEvent excepción: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
