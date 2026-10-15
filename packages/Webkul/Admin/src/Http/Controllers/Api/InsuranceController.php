<?php

namespace Webkul\Admin\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Services\InsuranceService;

/**
 * @OA\SecurityScheme(
      securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Ingresa el token de Sanctum: Bearer {token}"
 * )
 *
 * @OA\Tag(
 *     name="Insurance",
 *     description="Endpoints para verificación de seguros dentales"
 * )
 */
class InsuranceController extends Controller
{
    public function __construct(
        protected InsuranceService $insuranceService,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/insurance/verify",
     *     summary="Verificar cobertura de seguro de un paciente",
     *     description="Realiza la verificación del seguro dental de un paciente usando el CI y el tipo de seguro proporcionados. Equivalente a la verificación interna del CRM. Si el resultado es VIGENTE o EN_MORA, crea automáticamente una nota de actividad en el perfil del paciente y sus leads relacionados.",
     *     tags={"Insurance"},
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="application/json",
     *
     *             @OA\Schema(
     *                 required={"person_id", "ci", "insurance_type"},
     *
     *                 @OA\Property(
     *                     property="person_id",
     *                     type="integer",
     *                     description="ID del paciente (person) en el CRM",
     *                     example=42
     *                 ),
     *                 @OA\Property(
     *                     property="ci",
     *                     type="string",
     *                     description="Cédula de identidad del paciente",
     *                     example="12345678"
     *                 ),
     *                 @OA\Property(
     *                     property="insurance_type",
     *                     type="string",
     *                     description="Nombre del tipo de seguro. Valores soportados: 'Alianza', 'Nacional Seguro', 'Membresía Odontoking'",
     *                     example="Membresía Odontoking",
     *                     enum={"Alianza", "Nacional Seguro", "Membresía Odontoking"}
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Verificación completada. El campo 'status' indica el resultado.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="string", description="Resultado de la verificación",
     *                 enum={"VIGENTE", "EN_MORA", "VENCIDO", "NO_REGISTRADO", "SIN_SEGURO", "INDETERMINADO"},
     *                 example="VIGENTE"
     *             ),
     *             @OA\Property(property="message", type="string", example="¡Membresía activa! El paciente puede ser atendido con los beneficios Odontoking."),
     *             @OA\Property(property="badge", type="string", nullable=true, enum={"success", "warning", "danger", null}, example="success"),
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="seguro_name", type="string", example="Membresía Odontoking"),
     *             @OA\Property(
     *                 property="data",
     *                 nullable=true,
     *                 type="object",
     *                 description="Datos devueltos por la aseguradora. Los campos varían según el tipo de seguro.",
     *                 example={"CI": "12345678", "NOMBRE": "Juan Pérez", "CONTRATANTE": "Membresía Odontoking", "ESTADO": "VIGENTE", "VIGENCIA HASTA": "31/12/2025"}
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado. Se requiere Bearer token válido.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Paciente no encontrado",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Paciente no encontrado.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Error de validación en los campos enviados",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="The person_id field is required."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function verify(): JsonResponse
    {
        $data = request()->validate([
            'person_id'      => ['required', 'integer', 'exists:persons,id'],
            'ci'             => ['required', 'string', 'max:20'],
            'insurance_type' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->insuranceService->verifyDirect(
            (int) $data['person_id'],
            $data['ci'],
            $data['insurance_type'],
        );

        // 424 Failed Dependency: la aseguradora externa no respondió/autenticó.
        // No usar 5xx: el reverse-proxy (Easypanel) intercepta los 502/503 del
        // upstream y devuelve su propia página HTML, ocultando este JSON al cliente.
        $httpStatus = $result['success'] === false && $result['status'] === 'INDETERMINADO' ? 424 : 200;

        return response()->json($result, $httpStatus);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/insurance/verify",
     *     summary="Verificar seguro por cédula - Agente WhatsApp",
     *     description="Verifica si un paciente tiene seguro dental activo buscándolo por cédula (ci_paciente) y nombre de aseguradora en texto libre. Diseñado para el agente de WhatsApp. Responde con shape anti-enumeración: la respuesta es idéntica para 'paciente no encontrado' y 'sin seguro activo', para evitar revelar si un CI existe en el sistema. Rate limit: 10 req/min por token.",
     *     tags={"Insurance"},
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="ci_paciente",
     *         in="query",
     *         required=true,
     *         description="Cédula de identidad del paciente (custom attribute ci_paciente del Person)",
     *
     *         @OA\Schema(type="string", minLength=3, maxLength=30, example="0912345678")
     *     ),
     *
     *     @OA\Parameter(
     *         name="seguro_paciente",
     *         in="query",
     *         required=true,
     *         description="Nombre de la empresa aseguradora en texto libre (ej. 'Alianza', 'Nacional Vida', 'Membresía Odontoking')",
     *
     *         @OA\Schema(type="string", minLength=2, maxLength=100, example="Alianza")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Verificación completada. has_insurance:true indica seguro VIGENTE o EN_MORA.",
     *
     *         @OA\JsonContent(
     *             oneOf={
     *
     *                 @OA\Schema(
     *                     description="Seguro activo",
     *
     *                     @OA\Property(property="has_insurance", type="boolean", example=true),
     *                     @OA\Property(property="insurance_name", type="string", example="Seguros Equinoccial"),
     *                     @OA\Property(property="policy_number", type="string", nullable=true, example="POL-2025-00123"),
     *                     @OA\Property(property="coverage_type", type="string", example="dental"),
     *                     @OA\Property(property="valid_until", type="string", nullable=true, example="2026-12-31"),
     *                     @OA\Property(property="covered_services", type="array", @OA\Items(type="string"), example={"limpieza","radiografia"}),
     *                     @OA\Property(property="patient_name", type="string", nullable=true, example="Adenilza Flores"),
     *                     @OA\Property(property="patient_id", type="integer", nullable=true, example=55)
     *                 ),
     *
     *                 @OA\Schema(
     *                     description="Sin seguro o paciente no encontrado (misma respuesta - anti-enumeración)",
     *
     *                     @OA\Property(property="has_insurance", type="boolean", example=false),
     *                     @OA\Property(property="patient_found", type="boolean", example=false)
     *                 )
     *             }
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido o ausente",
     *
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Unauthenticated."))
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Parámetros faltantes o inválidos",
     *
     *         @OA\JsonContent(@OA\Property(property="message", type="string"), @OA\Property(property="errors", type="object"))
     *     ),
     *
     *     @OA\Response(
     *         response=429,
     *         description="Rate limit excedido (máx 10 req/min por token)",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Too many requests. Maximum 10 verifications per minute per token."),
     *             @OA\Property(property="retry_after", type="integer", example=60)
     *         )
     *     )
     * )
     */
    public function verifyForAgent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ci_paciente'     => ['required', 'string', 'min:3', 'max:30'],
            'seguro_paciente' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $ci = trim($data['ci_paciente']);
        $seguroName = trim($data['seguro_paciente']);
        $webhookUrl = env('SEGUROS_WEBHOOK_URL', 'https://n8n.sofopolis.com/webhook/seguros-verify');

        try {
            $webhookResponse = $this->rememberInsuranceRequest(
                'insurance_verify_agent_'.md5($seguroName.'|'.$ci),
                function () use ($webhookUrl, $seguroName, $ci) {
                    $response = Http::timeout(30)->post($webhookUrl, [
                        'empresa_seguro'   => $seguroName,
                        'carnet_identidad' => $ci,
                    ]);

                    return [
                        'failed' => $response->failed(),
                        'data'   => $response->json(),
                    ];
                }
            );

            if ($webhookResponse['failed']) {
                $this->logAudit($request, $ci, $seguroName, false);

                return response()->json(['has_insurance' => false, 'patient_found' => false], 200);
            }

            $webhookData = $webhookResponse['data'];
            $success = $webhookData['success'] ?? false;
            $results = $webhookData['data'] ?? [];

            $hasInsurance = $success
                && ! empty($results)
                && ! (is_string($results) && strtolower(trim($results)) === 'no registrado');

            $this->logAudit($request, $ci, $seguroName, $hasInsurance);

            if (! $hasInsurance) {
                usleep(random_int(50000, 150000));

                return response()->json(['has_insurance' => false, 'patient_found' => false]);
            }

            $row = is_array($results) ? ($results[0] ?? $results) : [];
            $validUntil = $this->extractField($row, ['VIGENCIA HASTA', 'VENCIMIENTO', 'valid_until']);

            return response()->json([
                'has_insurance'    => true,
                'insurance_name'   => $seguroName,
                'policy_number'    => $this->extractField($row, ['POLIZA', 'NUMERO POLIZA', 'policy_number']),
                'coverage_type'    => $this->extractField($row, ['TIPO COBERTURA', 'coverage_type', 'PLAN']) ?? 'dental',
                'valid_until'      => $validUntil,
                'covered_services' => $this->extractField($row, ['SERVICIOS', 'covered_services']) ?? [],
                'patient_name'     => $this->extractField($row, ['NOMBRE COMPLETO', 'NOMBRE', 'Nombre', 'patient_name']),
                'raw'              => $row,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[verifyForAgent] Webhook error', ['error' => $e->getMessage()]);

            return response()->json(['has_insurance' => false, 'patient_found' => false], 200);
        }
    }

    /**
     * Escribe una fila en insurance_audit_logs con datos hasheados.
     * Nunca loguea CI, token ni nombre en claro. Falla silenciosamente.
     */
    private function logAudit(Request $request, string $ci, string $seguro, bool $result): void
    {
        try {
            $tokenRaw = optional($request->user()?->currentAccessToken())->token ?? 'unknown';
            $tokenHash = hash('sha256', $tokenRaw);

            DB::table('insurance_audit_logs')->insert([
                'token_hash'  => $tokenHash,
                'ci_hash'     => hash('sha256', $ci),
                'seguro_hash' => hash('sha256', strtolower(trim($seguro))),
                'result'      => $result,
                'ip_address'  => $request->ip(),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[InsuranceAudit] Error al escribir audit log', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Busca un campo en el array de datos del driver por múltiples claves posibles.
     * Necesario porque cada aseguradora devuelve keys diferentes (NOMBRE COMPLETO, NOMBRE, etc.)
     */
    private function extractField(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                return $data[$key];
            }

            foreach ($data as $dataKey => $value) {
                if (strtolower((string) $dataKey) === strtolower($key)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function rememberInsuranceRequest(string $key, \Closure $callback): array
    {
        if (! $this->insuranceRequestCacheEnabled()) {
            return $callback();
        }

        return Cache::remember($key, (int) config('services.insurance.cache_ttl', 3600), $callback);
    }

    private function insuranceRequestCacheEnabled(): bool
    {
        return filter_var(config('services.insurance.cache_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }
}
