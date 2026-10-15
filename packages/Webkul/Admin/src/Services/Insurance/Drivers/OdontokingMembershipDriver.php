<?php

namespace Webkul\Admin\Services\Insurance\Drivers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\Insurance\Contracts\InsuranceDriverInterface;
use Webkul\Admin\Services\Insurance\GoogleSheetsInsuranceSource;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;

/**
 * Driver para Membresía Odontoking.
 *
 * Busca la cédula en el padrón de membresías de Google Sheets
 * (GoogleSheetsInsuranceSource; antes lo leía un webhook de n8n).
 * Además, lee el atributo `estado_seguro_paciente` de la BD como contexto adicional.
 */
class OdontokingMembershipDriver implements InsuranceDriverInterface
{
    const OPTION_ID_EN_MORA = 91;

    const OPTION_ID_PROBLEMA = 92;

    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected AttributeValueRepository $attributeValueRepository,
        protected GoogleSheetsInsuranceSource $sheets,
    ) {}

    public function verify(object $person, string $ci): array
    {
        try {
            // Padrón en Google Sheets (antes lo leía n8n y devolvía lo mismo).
            $data = $this->sheets->lookup('membresia', $ci);

            $result = $this->mapResponse($data);
            $this->updateInsuranceAttributes($person, $result['status']);

            return $result;
        } catch (\Exception $e) {
            Log::error('[OdontokingMembershipDriver] No se pudo leer el padrón de Google Sheets: '.$e->getMessage());
            $this->updateInsuranceAttributes($person, 'INDETERMINADO');

            return $this->indeterminate('No pudimos consultar el padrón de membresías en este momento. Inténtalo más tarde.');
        }
    }

    protected function mapResponse(array $data): array
    {
        $success = $data['success'] ?? false;
        $results = $data['data'] ?? [];

        if (! $success || empty($results) || (is_string($results) && trim(strtolower($results)) === 'no registrado')) {
            return [
                'status'  => 'NO_REGISTRADO',
                'message' => 'El paciente no figura en la base de datos de esta aseguradora.',
                'badge'   => 'warning',
                'success' => true,
                'data'    => null,
            ];
        }

        $insuranceData = is_array($results) ? ($results[0] ?? null) : $results;

        if (! $insuranceData) {
            return [
                'status'  => 'NO_REGISTRADO',
                'message' => 'El paciente no figura en la base de datos de esta aseguradora.',
                'badge'   => 'warning',
                'success' => true,
                'data'    => null,
            ];
        }

        [$estado, $vigenciaHasta] = $this->resolveEstado($insuranceData);

        $displayData = [
            'CI'            => $insuranceData['CI'] ?? '',
            'NOMBRE'        => $insuranceData['Nombre'] ?? ($insuranceData['Paciente_Original'] ?? ''),
            'CONTRATANTE'   => 'Membresía Odontoking',
            'ESTADO'        => $estado,
            'TELEFONO'      => $insuranceData['Telefono'] ?? '',
            'OBSERVACIONES' => $insuranceData['Observaciones'] ?? '',
            'SEGURO'        => $insuranceData['Seguro'] ?? '',
            'SEGURO2'       => $insuranceData['Seguro2'] ?? '',
        ];

        if ($vigenciaHasta) {
            $displayData['VIGENCIA HASTA'] = $vigenciaHasta->format('d/m/Y');
        }

        if ($estado === 'VIGENTE') {
            return [
                'status'  => 'VIGENTE',
                'message' => '¡Membresía activa! El paciente puede ser atendido con los beneficios Odontoking.',
                'badge'   => 'success',
                'success' => true,
                'data'    => $displayData,
            ];
        }

        if ($estado === 'VENCIDO') {
            return [
                'status'  => 'VENCIDO',
                'message' => 'La membresía Odontoking está vencida.'.($vigenciaHasta ? ' Venció el '.$vigenciaHasta->format('d/m/Y').'.' : ''),
                'badge'   => 'danger',
                'success' => true,
                'data'    => $displayData,
            ];
        }

        if ($estado === 'EN_MORA') {
            return [
                'status'  => 'EN_MORA',
                'message' => 'Atención: La membresía Odontoking está suspendida. El paciente podría tener pagos pendientes.',
                'badge'   => 'danger',
                'success' => true,
                'data'    => $displayData,
            ];
        }

        return [
            'status'  => 'INDETERMINADO',
            'message' => 'La membresía Odontoking fue encontrada, pero el estado no pudo interpretarse con seguridad.',
            'badge'   => null,
            'success' => false,
            'data'    => $displayData,
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

            if ($leadAttribute && isset($person->leads) && $person->leads->count() > 0) {
                foreach ($person->leads as $lead) {
                    app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
                        'entity_id'                   => $lead->id,
                        'entity_type'                 => 'leads',
                        'estado_seguro_paciente_cita' => $optionId,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('[OdontokingMembershipDriver] Error actualizando atributos: '.$e->getMessage());
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
     * Extrae el estado desde el registro de Membresía Odontoking.
     * Una fila encontrada con Seguro/Seguro2 de Membresía y sin observaciones
     * negativas se considera vigente.
     */
    protected function resolveEstado(array $row): array
    {
        $observaciones = trim((string) ($row['Observaciones'] ?? ''));
        $observacionesNormalizadas = $this->normalizeText($observaciones);

        // En el padrón real (Google Sheets, 2026-10-12) la fecha viene como
        // "MF HASTA EL 15/10/2026", "MF VENCE 15/10/2026", "MF VENCE EL …" y a
        // veces con el día de un dígito ("9/10/2026"). Antes solo se entendía la
        // primera: ~440 membresías con "MF VENCE" pasaban como vigentes.
        if (preg_match('/MF\s+(?:HASTA|VENCE)(?:\s+EL)?\s+(\d{1,2}\/\d{1,2}\/\d{4})/i', $observaciones, $m)) {
            $fecha = \Carbon\Carbon::createFromFormat('j/n/Y', $m[1])->startOfDay();
            // Vale hasta el final de ese día, inclusive.
            $estado = $fecha->copy()->endOfDay()->isFuture() ? 'VIGENTE' : 'VENCIDO';

            return [$estado, $fecha];
        }

        $estadoExplicito = $this->normalizeText((string) ($row['ESTADO'] ?? ''));
        if ($estadoExplicito) {
            return [$this->mapEstadoText($estadoExplicito), null];
        }

        if ($observacionesNormalizadas) {
            if (str_contains($observacionesNormalizadas, 'VENCID')) {
                return ['VENCIDO', null];
            }

            if (
                str_contains($observacionesNormalizadas, 'MORA')
                || str_contains($observacionesNormalizadas, 'PAGO PENDIENTE')
                || str_contains($observacionesNormalizadas, 'PAGOS PENDIENTES')
                || str_contains($observacionesNormalizadas, 'SUSPENDID')
            ) {
                return ['EN_MORA', null];
            }

            if (
                str_contains($observacionesNormalizadas, 'VIGENTE')
                || str_contains($observacionesNormalizadas, 'ACTIV')
                || str_contains($observacionesNormalizadas, 'HABILITAD')
            ) {
                return ['VIGENTE', null];
            }
        }

        if ($this->hasMembershipInsurance($row)) {
            return ['VIGENTE', null];
        }

        return ['INDETERMINADO', null];
    }

    protected function hasMembershipInsurance(array $row): bool
    {
        $insuranceNames = [
            $row['Seguro'] ?? '',
            $row['Seguro2'] ?? '',
        ];

        foreach ($insuranceNames as $insuranceName) {
            $normalized = $this->normalizeText((string) $insuranceName);

            if (str_contains($normalized, 'MEMBRESIA') && str_contains($normalized, 'ODONTOKING')) {
                return true;
            }
        }

        return false;
    }

    protected function mapEstadoText(string $estado): string
    {
        if (str_contains($estado, 'VIGENTE') || str_contains($estado, 'ACTIV') || str_contains($estado, 'HABILITAD')) {
            return 'VIGENTE';
        }

        if (str_contains($estado, 'VENCID')) {
            return 'VENCIDO';
        }

        if (str_contains($estado, 'MORA') || str_contains($estado, 'PENDIENTE') || str_contains($estado, 'SUSPENDID')) {
            return 'EN_MORA';
        }

        return 'INDETERMINADO';
    }

    protected function normalizeText(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return strtoupper($value);
    }
}
