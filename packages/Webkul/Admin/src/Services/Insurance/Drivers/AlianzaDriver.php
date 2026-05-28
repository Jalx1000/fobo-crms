<?php

namespace Webkul\Admin\Services\Insurance\Drivers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\Insurance\Contracts\InsuranceDriverInterface;

/**
 * Driver para Alianza Seguros via MedinetGo API.
 *
 * Flujo por request (sin caché):
 *  1. POST /LoginUserProv → accessToken + nUsercode frescos
 *  2. GET  /OdontologyCoverage?ci={ci}&nUsercode={nUsercode}
 */
class AlianzaDriver implements InsuranceDriverInterface
{
    protected string $loginUrl;
    protected string $microserviceUrl;
    protected string $user;
    protected string $password;
    protected int    $timeout = 1000;

    const OPTION_ID_EN_MORA  = 91;
    const OPTION_ID_PROBLEMA = 92;

    public function __construct()
    {
        $this->loginUrl        = "https://devnet.alianza.com.bo/ApiGateway";
        $this->microserviceUrl = "https://devnet.alianza.com.bo/ApiGateway";
        $this->user            = env('ALIANZA_USER', '');
        $this->password        = env('ALIANZA_PASS', '');
    }

    public function verify(object $person, string $ci): array
    {
        try {
            [$token, $nUsercode] = $this->login();
            Log::info('[AlianzaDriver] Login exitoso', [
                'token'     => $token,
                'nUsercode' => $nUsercode,
            ]);
            if (! $token || ! $nUsercode) {
                $this->updateInsuranceAttributes($person, 'INDETERMINADO');
                return $this->indeterminate('No se pudo autenticar con el sistema de Alianza. Intenta nuevamente.');
            }

            return $this->checkCoverage($ci, $token, $nUsercode, $person);
        } catch (\Exception $e) {
            Log::error('[AlianzaDriver] Error Crítico: ' . $e->getMessage(), [
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

        if ($response->failed() || ! $response->json('success')) {
            Log::error('[AlianzaDriver] Login fallido', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);
            return [null, null];
        }

        $data      = $response->json('data');
        $token     = $data['accessToken'] ?? null;
        $nUsercode = $data['webClientVtime'][0]['nUsercode'] ?? null;

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

        if ($response->failed()) {
            $this->updateInsuranceAttributes($person, 'INDETERMINADO');
            return $this->indeterminate('La consulta con Alianza no pudo completarse. Por favor, verifica la conexión o contacta a soporte técnico.');
        }

        $body = $response->json();

        $rows = $body['data'] ?? [];

        if (($body['success'] ?? false) && is_array($rows) && ! empty($rows)) {
            $record = isset($rows[0]) ? $rows[0] : $rows;

            $estado = strtoupper($record['ESTADO'] ?? '');

            // Si el registro existe pero no tiene campo ESTADO reconocido, tratar como no registrado
            if (empty($estado)) {
                $this->updateInsuranceAttributes($person, 'NO_REGISTRADO');
                return [
                    'status'  => 'NO_REGISTRADO',
                    'message' => 'El paciente no figura con cobertura dental en Alianza: Sin cobertura',
                    'badge'   => 'warning',
                    'success' => false,
                    'data'    => null,
                ];
            }

            $badge  = $estado === 'VIGENTE' ? 'success' : 'warning';

            $this->updateInsuranceAttributes($person, $estado);

            return [
                'status'  => $estado,
                'message' => '¡Todo en orden! El paciente tiene cobertura dental activa en Alianza.',
                'badge'   => $badge,
                'success' => true,
                'data'    => [
                    'CI'                     => $record['NRO. DOCUMENTO']                   ?? $ci,
                    'CONTRATANTE'            => $record['CONTRATANTE']                      ?? '',
                    'NOMBRE COMPLETO'        => $record['NOMBRE COMPLETO']                  ?? '',
                    'ESTADO'                 => $record['ESTADO']                           ?? $estado,
                    'EDAD'                   => $record['EDAD']                             ?? null,
                    'CODIGO'                 => $record['CODIGO DE ASEGURADO']              ?? '',
                    'COBERTURA ODONTOLOGICA' => $record['COBERTURA ADICIONAL ODONTOLOGICA'] ?? '',
                    'COASEGURO'              => $record['COASEGURO ODONTOLOGICO']           ?? '',
                    'CLINICA'                => $record['CLINICA ODONTOLOGICA']             ?? '',
                    'RELACION'               => $record['RELACION']                         ?? '',
                    'FECHA INGRESO'          => $record['FECHA DE INGRESO']                 ?? '',
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
                'success' => false,
                'data'    => null,
            ];
        }

        $this->updateInsuranceAttributes($person, 'NO_REGISTRADO');
        return [
            'status'  => 'NO_REGISTRADO',
            'message' => 'El paciente no figura con cobertura dental en Alianza: ' . ($body['messageError'] ?? 'Sin cobertura'),
            'badge'   => 'warning',
            'success' => false,
            'data'    => null,
        ];
    }

    protected function updateInsuranceAttributes(object $person, string $status): void
    {
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
            Log::error('[AlianzaDriver] Error actualizando atributos: ' . $e->getMessage());
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
}
