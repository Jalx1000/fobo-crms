<?php

namespace Webkul\Admin\Services\Insurance\Drivers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\Insurance\Contracts\InsuranceDriverInterface;

/**
 * Driver para Alianza Seguros via MedinetGo API (ApiGateway).
 *
 * Flujo por request:
 *  1. POST /LoginUserProv      → accessToken + nUsercode frescos
 *  2. GET  /OdontologyCoverage?ci={ci}&nUsercode={nUsercode}
 *
 * URLs, credenciales y timeout se leen de config('services.alianza.*'),
 * configurables vía .env. success=true en la cobertura ya significa que el
 * cliente tiene cobertura odontológica vigente.
 */
class AlianzaDriver implements InsuranceDriverInterface
{
    protected string $loginUrl;

    protected string $microserviceUrl;

    protected string $user;

    protected string $password;

    protected int $timeout;

    protected bool $logRawToken;

    const OPTION_ID_EN_MORA = 91;

    const OPTION_ID_PROBLEMA = 92;

    const REJECTED_MESSAGE = 'Alianza rechaza el usuario y contraseña de la clínica. Hay que pedirle a Alianza que restablezca la contraseña y actualizarla en el sistema.';

    /** Marca de "credenciales rechazadas", atada a la clave: cambiarla la anula. */
    protected function rejectedKey(): string
    {
        return 'alianza:credentials_rejected:'.md5($this->user.'|'.$this->password);
    }

    public function __construct()
    {
        $this->loginUrl = rtrim((string) config('services.alianza.login_url'), '/');
        $this->microserviceUrl = rtrim((string) config('services.alianza.microservice_url'), '/');
        $this->user = (string) config('services.alianza.user', '');
        $this->password = (string) config('services.alianza.password', '');
        $this->timeout = (int) config('services.alianza.timeout', 15);
        $this->logRawToken = (bool) config('services.alianza.log_raw_token', false);
    }

    public function verify(object $person, string $ci): array
    {
        // Alianza ya rechazó el usuario de la clínica: cada intento con la
        // clave mala puede renovar su bloqueo, así que no se insiste hasta que
        // pase el plazo o se cambie la clave en el .env (la marca es por clave).
        if (Cache::has($this->rejectedKey())) {
            $this->updateInsuranceAttributes($person, 'INDETERMINADO');

            return $this->indeterminate(self::REJECTED_MESSAGE);
        }

        try {
            [$token, $nUsercode] = $this->login();

            if (! $token || ! $nUsercode) {
                $this->updateInsuranceAttributes($person, 'INDETERMINADO');

                return $this->indeterminate(Cache::has($this->rejectedKey())
                    ? self::REJECTED_MESSAGE
                    : 'No se pudo autenticar con el sistema de Alianza. Intenta nuevamente.');
            }

            Log::info('[AlianzaDriver] Login exitoso', [
                'token'     => $this->maskToken($token),
                'nUsercode' => $nUsercode,
            ]);

            return $this->checkCoverage($ci, $token, $nUsercode, $person);
        } catch (\Exception $e) {
            Log::error('[AlianzaDriver] Error Crítico: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'ci'    => $ci,
            ]);

            $this->updateInsuranceAttributes($person, 'INDETERMINADO');

            return $this->indeterminate('La consulta con Alianza no pudo completarse. Por favor, verifica la conexión o contacta a soporte técnico.');
        }
    }

    protected function login(): array
    {
        $response = Http::timeout($this->timeout)
            ->post("{$this->loginUrl}/LoginUserProv", [
                'user'         => $this->user,
                'password'     => $this->password,
                'module'       => 'string',
                'method'       => 'string',
                'nApplication' => 0,
            ]);

        $data = $response->json('data');
        $token = $data['accessToken'] ?? null;
        $nUsercode = $data['webClientVtime'][0]['nUsercode'] ?? null;

        // Credenciales rechazadas: 401 "Usuario o Contraseña incorrectos", o el
        // 200 con success=true pero sin token y la clave marcada como inválida
        // (sIndBlockedPass 1 = bloqueo temporal, 2 = clave inválida/vencida).
        $blockedPass = (string) ($data['webClientVtime'][0]['sIndBlockedPass'] ?? '');

        if ($response->status() === 401 || (! $token && in_array($blockedPass, ['1', '2'], true))) {
            $minutes = max(1, (int) config('services.alianza.rejected_backoff_minutes', 30));
            Cache::put($this->rejectedKey(), now()->toIso8601String(), now()->addMinutes($minutes));

            Log::error('[AlianzaDriver] Alianza rechazó el usuario de la clínica: hay que renovar la clave (ALIANZA_PASS). No se reintenta por '.$minutes.' min.', [
                'status'          => $response->status(),
                'mensaje'         => $response->json('messageError') ?? ($data['webClientVtime'][0]['sMessage'] ?? null),
                'sIndBlockedPass' => $blockedPass ?: null,
            ]);

            return [null, null];
        }

        if ($response->failed() || ! $response->json('success')) {
            Log::error('[AlianzaDriver] Login fallido', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return [null, null];
        }

        return [$token, (int) $nUsercode];
    }

    protected function checkCoverage(string $ci, string $token, int $nUsercode, object $person): array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($token)
            ->get("{$this->microserviceUrl}/OdontologyCoverage", [
                'ci'        => $ci,
                'nUsercode' => $nUsercode,
            ]);

        $body = $response->json();

        // En MedinetGo, success=true significa que el cliente tiene cobertura
        // odontológica vigente. La respuesta trae: ci, clientCode, clientName, age.
        if ($response->successful() && ($body['success'] ?? false)) {
            $rows = $body['data'] ?? [];
            $record = (array) (is_array($rows) && isset($rows[0]) ? $rows[0] : $rows);

            $this->updateInsuranceAttributes($person, 'VIGENTE');

            return [
                'status'  => 'VIGENTE',
                'message' => '¡Todo en orden! El paciente tiene cobertura dental activa en Alianza.',
                'badge'   => 'success',
                'success' => true,
                'data'    => [
                    'CI'              => $record['ci'] ?? $ci,
                    'CODIGO'          => $record['clientCode'] ?? '',
                    'NOMBRE COMPLETO' => $record['clientName'] ?? '',
                    'ESTADO'          => 'VIGENTE',
                    'EDAD'            => $record['age'] ?? null,
                ],
            ];
        }

        $errorMsg = strtolower($body['messageError'] ?? '');

        if (str_contains($errorMsg, 'pago pendiente') || str_contains($errorMsg, 'comuníquese')) {
            $this->updateInsuranceAttributes($person, 'EN_MORA');

            return [
                'status'  => 'EN_MORA',
                'message' => 'Atención: El paciente tiene pagos pendientes en Alianza. Debe comunicarse con la compañía.',
                'badge'   => 'danger',
                'success' => true,
                'data'    => null,
            ];
        }

        $this->updateInsuranceAttributes($person, 'NO_REGISTRADO');

        return [
            'status'  => 'NO_REGISTRADO',
            'message' => 'El paciente no figura con cobertura dental en Alianza: '.($body['messageError'] ?? 'Sin cobertura'),
            'badge'   => 'warning',
            'success' => true,
            'data'    => null,
        ];
    }

    protected function updateInsuranceAttributes(object $person, string $status): void
    {
        // Consulta solo por cédula (InsuranceService::verifyCi): sin ficha no
        // se escribe nada. Antes quedaban valores colgados de la persona 0.
        if (empty($person->id)) {
            return;
        }

        try {
            $optionId = $this->resolveOptionId($status);

            if (! $optionId) {
                return;
            }

            $personAttribute = DB::table('attributes')
                ->where('code', 'estado_seguro_paciente')
                ->where('entity_type', 'persons')
                ->first();

            if ($personAttribute) {
                app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
                    'entity_id'              => $person->id,
                    'entity_type'            => 'persons',
                    'estado_seguro_paciente' => $optionId,
                ]);
            }

            $leadAttribute = DB::table('attributes')
                ->where('code', 'estado_seguro_paciente_cita')
                ->where('entity_type', 'leads')
                ->first();

            if ($leadAttribute && $person->leads->count() > 0) {
                foreach ($person->leads as $lead) {
                    app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
                        'entity_id'                   => $lead->id,
                        'entity_type'                 => 'leads',
                        'estado_seguro_paciente_cita' => $optionId,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('[AlianzaDriver] Error actualizando atributos: '.$e->getMessage());
        }
    }

    /**
     * VIGENTE  → busca por nombre "Vigente" (evita hardcodear el ID).
     * EN_MORA  → ID fijo 91.
     * Resto    → ID fijo 92.
     */
    protected function resolveOptionId(string $status): ?int
    {
        if ($status === 'VIGENTE') {
            $attr = DB::table('attributes')
                ->where('code', 'estado_seguro_paciente')
                ->where('entity_type', 'persons')
                ->first();

            if (! $attr) {
                return null;
            }

            return DB::table('attribute_options')
                ->where('attribute_id', $attr->id)
                ->where('name', 'Vigente')
                ->value('id');
        }

        if ($status === 'EN_MORA') {
            return self::OPTION_ID_EN_MORA;
        }

        return self::OPTION_ID_PROBLEMA;
    }

    protected function indeterminate(string $message): array
    {
        return [
            'status'  => 'INDETERMINADO',
            'message' => $message,
            'badge'   => null,
            'success' => false,
            'data'    => null,
        ];
    }

    /**
     * Devuelve el token para logging. Por defecto un hash truncado (no reversible);
     * solo en claro si ALIANZA_LOG_RAW_TOKEN=true (depuración puntual).
     */
    protected function maskToken(?string $token): ?string
    {
        if (! $token) {
            return null;
        }

        if ($this->logRawToken) {
            return $token;
        }

        return 'sha256:'.substr(hash('sha256', $token), 0, 12);
    }
}
