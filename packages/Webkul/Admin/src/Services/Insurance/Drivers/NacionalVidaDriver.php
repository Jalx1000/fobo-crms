<?php

namespace Webkul\Admin\Services\Insurance\Drivers;

use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\Insurance\Contracts\InsuranceDriverInterface;
use Webkul\Admin\Services\Insurance\GoogleSheetsInsuranceSource;

/**
 * Driver para Nacional Vida: busca la cédula en su padrón de Google Sheets
 * (GoogleSheetsInsuranceSource) y mapea la fila a los estados del CRM.
 */
class NacionalVidaDriver implements InsuranceDriverInterface
{
    public function __construct(protected GoogleSheetsInsuranceSource $sheets) {}

    public function verify(object $person, string $ci): array
    {
        try {
            // Padrón en Google Sheets (antes lo leía n8n y devolvía lo mismo).
            $data = $this->sheets->lookup('nacional_vida', $ci);

            return $this->mapResponse($data, $person);
        } catch (\Exception $e) {
            Log::error('[NacionalVidaDriver] No se pudo leer el padrón de Google Sheets: '.$e->getMessage());

            return [
                'status'  => 'INDETERMINADO',
                'message' => 'No pudimos consultar el padrón de Nacional Vida en este momento. Inténtalo más tarde.',
                'badge'   => null,
                'success' => false,
                'data'    => null,
            ];
        }
    }

    protected function mapResponse(array $data, object $person): array
    {
        $success = $data['success'] ?? false;
        $results = $data['data'] ?? [];
        Log::info('[NacionalVidaDriver] Respuesta de la aseguradora', $data);
        if (! $success || empty($results) || (is_string($results) && trim(strtolower($results)) === 'no registrado')) {
            return [
                'status'  => 'NO_REGISTRADO',
                'message' => 'El paciente no figura en la base de datos de esta aseguradora.',
                'badge'   => 'warning',
                'success' => true,
                'data'    => null,
            ];
        }

        $insuranceData = is_array($results) ? ($results[0] ?? null) : null;

        if (! $insuranceData) {
            return [
                'status'  => 'NO_REGISTRADO',
                'message' => 'El paciente no figura en la base de datos de esta aseguradora.',
                'badge'   => 'warning',
                'success' => true,
                'data'    => null,
            ];
        }

        $estado = strtoupper($insuranceData['ESTADO'] ?? '');

        return $this->processFinalResponse($person, $estado, $insuranceData);
    }

    protected function processFinalResponse($person, $estado, $insuranceData): array
    {
        // Actualizar estados en Person y Leads
        $this->updateInsuranceAttributes($person, $estado);

        $displayData = [
            'CI'                     => $insuranceData['NRO DOCUMENTO'] ?? ($insuranceData['NRO. DOCUMENTO'] ?? ''),
            'NOMBRE COMPLETO'        => $insuranceData['NOMBRE COMPLETO'] ?? '',
            'CONTRATANTE'            => $insuranceData['CONTRATANTE'] ?? '',
            'ESTADO'                 => $insuranceData['ESTADO'] ?? $estado,
            'EDAD'                   => $insuranceData['EDAD'] ?? null,
            'CODIGO'                 => $insuranceData['CODIGO DE ASEGURADO'] ?? '',
            'COBERTURA ODONTOLOGICA' => $insuranceData['COBERTURA ADICIONAL ODONTOLOGICA'] ?? '',
            'COASEGURO'              => $insuranceData['COASEGURO ODONTOLOGICO'] ?? '',
            'CLINICA'                => $insuranceData['CLINICA ODONTOLOGICA'] ?? '',
            'RELACION'               => $insuranceData['RELACION'] ?? '',
            'FECHA INGRESO'          => $insuranceData['FECHA DE INGRESO'] ?? '',
        ];

        if ($estado === 'VIGENTE') {
            return [
                'status'  => 'VIGENTE',
                'message' => '¡Todo en orden! El seguro está activo y el paciente puede ser atendido.',
                'badge'   => 'success',
                'success' => true,
                'data'    => $displayData,
            ];
        }

        return [
            'status'  => 'EN_MORA',
            'message' => 'Atención: El seguro está suspendido. El paciente podría tener pagos pendientes.',
            'badge'   => 'danger',
            'success' => true,
            'data'    => $displayData,
        ];
    }

    /**
     * Actualiza los custom attributes de Person y Leads asociados.
     */
    protected function updateInsuranceAttributes($person, string $estado): void
    {
        // Consulta solo por cédula (InsuranceService::verifyCi): sin ficha no
        // se escribe nada. Antes quedaban valores colgados de la persona 0.
        if (empty($person->id)) {
            return;
        }

        try {
            $statusMap = [
                'VIGENTE' => 'Vigente',
                'EN_MORA' => 'Pagos pendientes',
            ];

            $targetName = $statusMap[$estado] ?? null;

            if (! $targetName) {
                return;
            }

            // 1. Actualizar Person: estado_seguro_paciente
            $personAttribute = \Illuminate\Support\Facades\DB::table('attributes')
                ->where('code', 'estado_seguro_paciente')
                ->where('entity_type', 'persons')
                ->first();

            if ($personAttribute) {
                $option = \Illuminate\Support\Facades\DB::table('attribute_options')
                    ->where('attribute_id', $personAttribute->id)
                    ->where('name', $targetName)
                    ->first();

                if ($option) {
                    app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
                        'entity_id'              => $person->id,
                        'entity_type'            => 'persons',
                        'estado_seguro_paciente' => $option->id,
                    ]);
                }
            }

            // 2. Actualizar Leads asociados: estado_seguro_paciente_cita
            $leadAttribute = \Illuminate\Support\Facades\DB::table('attributes')
                ->where('code', 'estado_seguro_paciente_cita')
                ->where('entity_type', 'leads')
                ->first();

            if ($leadAttribute && $person->leads->count() > 0) {
                $option = \Illuminate\Support\Facades\DB::table('attribute_options')
                    ->where('attribute_id', $leadAttribute->id)
                    ->where('name', $targetName)
                    ->first();

                if ($option) {
                    foreach ($person->leads as $lead) {
                        app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->save([
                            'entity_id'                   => $lead->id,
                            'entity_type'                 => 'leads',
                            'estado_seguro_paciente_cita' => $option->id,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('[NacionalVidaDriver] Error actualizando atributos: '.$e->getMessage());
        }
    }
}
