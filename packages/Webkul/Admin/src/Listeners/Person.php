<?php

namespace Webkul\Admin\Listeners;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Email\Repositories\EmailRepository;

class Person
{
    /**
     * Mapeo nombre CRM → nombre seguro en SMD.
     * "Alianza" local → "Alianza Particular" en SMD (acuerdo comercial).
     */
    private const INSURANCE_MAP = [
        'Alianza'              => 'Alianza Particular',
        'Nacional Vida'        => 'Nacional Vida',
        'Membresia Odontoking' => 'Membresía Odontoking',
        'No tiene'             => 'Particular',
    ];

    public function __construct(
        protected EmailRepository $emailRepository,
        protected ShareMeDataService $shareMeDataService,
        protected PersonRepository $personRepository,
        protected AttributeRepository $attributeRepository,
    ) {}

    public function linkToEmail($person): void
    {
        if (! request('email_id')) {
            return;
        }

        $this->emailRepository->update(['person_id' => $person->id], request('email_id'));
    }

    /**
     * Sincroniza el paciente recién creado con ShareMeData.
     */
    public function syncToSmd($person): void
    {
        // Tessa no usa ShareMeData: la sincronización queda desactivada por flag.
        if (! config('tessa.features.insurance_smd', false)) {
            return;
        }

        try {
            $person = $person->fresh(); // garantiza atributos post-save cargados

            if ($person->smd_patient_id) {
                return;
            }

            $phone = $this->extractPhone($person);

            if (! $phone) {
                Log::debug('[SMD] syncToSmd: paciente sin teléfono, omitiendo', ['person_id' => $person->id]);

                return;
            }

            // Buscar por teléfono o email antes de crear
            $smdId = $this->findInSmd($person, $phone);

            if (! $smdId) {
                $result = $this->shareMeDataService->createPatient(
                    $this->buildSmdPayload($person, $phone)
                );

                if ($result['success']) {
                    $smdId = $result['data']['_id'] ?? null;
                } elseif ($result['duplicate'] ?? false) {
                    $smdId = $this->shareMeDataService->searchPatient($phone)[0]['_id'] ?? null;
                } else {
                    Log::warning('[SMD] syncToSmd: no se pudo crear paciente', [
                        'person_id' => $person->id,
                        'message'   => $result['message'] ?? '',
                    ]);

                    return;
                }
            }

            if ($smdId) {
                // DB directo para evitar que PersonRepository::sanitize() borre user_id (Krayin-dev C-1)
                DB::table('persons')->where('id', $person->id)->update(['smd_patient_id' => $smdId]);
                Log::info('[SMD] syncToSmd: paciente vinculado', ['person_id' => $person->id, 'smd_id' => $smdId]);
            }
        } catch (\Exception $e) {
            Log::error('[SMD] syncToSmd excepción', ['person_id' => $person->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Al actualizar un paciente:
     * 1. Busca en SMD por teléfono, luego por email
     * 2. Si existe → actualiza sus datos (PATCH)
     * 3. Si no existe → crea en SMD
     * En todos los casos guarda el smd_patient_id si no lo tenía.
     */
    public function updateInSmd($person): void
    {
        // Tessa no usa ShareMeData: la sincronización queda desactivada por flag.
        if (! config('tessa.features.insurance_smd', false)) {
            return;
        }

        try {
            $person = $person->fresh(); // garantiza atributos post-save cargados
            $phone = $this->extractPhone($person);

            // C-1: guard temprano - sin teléfono no hay nada útil que hacer
            if (! $phone) {
                Log::debug('[SMD] updateInSmd: paciente sin teléfono, omitiendo', ['person_id' => $person->id]);

                return;
            }

            $payload = $this->buildSmdPayload($person, $phone);

            // Buscar en SMD por teléfono → luego por email
            $smdId = $this->findInSmd($person, $phone);

            if ($smdId) {
                // Encontrado → actualizar datos
                $result = $this->shareMeDataService->updatePatient($smdId, $payload);

                if ($result['success']) {
                    Log::info('[SMD] updateInSmd: paciente actualizado', [
                        'person_id' => $person->id,
                        'smd_id'    => $smdId,
                    ]);
                } elseif ($result['not_found'] ?? false) {
                    // El ID guardado ya no existe en SMD → limpiar y re-crear
                    Log::warning('[SMD] updateInSmd: ID no existe en SMD, re-buscando', [
                        'person_id' => $person->id,
                        'smd_id'    => $smdId,
                    ]);

                    // Limpiar ID obsoleto para que findInSmd busque por teléfono/email
                    DB::table('persons')->where('id', $person->id)->update(['smd_patient_id' => null]);
                    $person->smd_patient_id = null;
                    $smdId = null;
                } else {
                    Log::warning('[SMD] updateInSmd: fallo al actualizar', [
                        'person_id' => $person->id,
                        'smd_id'    => $smdId,
                        'message'   => $result['message'] ?? '',
                    ]);
                }
            }

            if (! $smdId) {
                // No encontrado o ID obsoleto → buscar de nuevo por teléfono/email antes de crear
                if (! $phone) {
                    return;
                }

                $smdId = $this->findInSmd($person, $phone);

                if ($smdId) {
                    // Encontrado tras re-búsqueda → actualizar (M-3: capturar resultado)
                    $reResult = $this->shareMeDataService->updatePatient($smdId, $payload);
                    if (! $reResult['success']) {
                        Log::warning('[SMD] updateInSmd: re-update falló tras re-búsqueda', [
                            'person_id' => $person->id,
                            'smd_id'    => $smdId,
                            'message'   => $reResult['message'] ?? '',
                        ]);
                    }
                    $this->personRepository->update(
                        ['smd_patient_id' => $smdId, 'entity_type' => 'persons'],
                        $person->id
                    );
                    Log::info('[SMD] updateInSmd: re-vinculado tras re-búsqueda', [
                        'person_id' => $person->id,
                        'smd_id'    => $smdId,
                    ]);

                    return;
                }

                $result = $this->shareMeDataService->createPatient($payload);

                if ($result['success']) {
                    $smdId = $result['data']['_id'] ?? null;
                } elseif ($result['duplicate'] ?? false) {
                    // Duplicado en SMD → buscarlo de nuevo
                    $smdId = $this->shareMeDataService->searchPatient($phone)[0]['_id'] ?? null;
                } else {
                    Log::warning('[SMD] updateInSmd: no se pudo crear', [
                        'person_id' => $person->id,
                        'message'   => $result['message'] ?? '',
                    ]);

                    return;
                }

                Log::info('[SMD] updateInSmd: paciente creado en SMD', [
                    'person_id' => $person->id,
                    'smd_id'    => $smdId,
                ]);
            }

            // Guardar smd_patient_id si cambió o no lo tenía
            if ($smdId && $person->smd_patient_id !== $smdId) {
                // DB directo para evitar que PersonRepository::sanitize() borre user_id (Krayin-dev C-1)
                DB::table('persons')->where('id', $person->id)->update(['smd_patient_id' => $smdId]);
            }
        } catch (\Exception $e) {
            Log::error('[SMD] updateInSmd excepción', ['person_id' => $person->id, 'error' => $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Busca el paciente en SMD: primero por smd_patient_id (si existe),
     * luego por teléfono, luego por email. Retorna el _id de SMD o null.
     */
    private function findInSmd($person, ?string $phone): ?string
    {
        // 1. Ya tiene ID guardado → usarlo directamente
        if ($person->smd_patient_id) {
            return $person->smd_patient_id;
        }

        // 2. Buscar por teléfono
        if ($phone) {
            $results = $this->shareMeDataService->searchPatient($phone);
            if (! empty($results)) {
                return $results[0]['_id'] ?? null;
            }
        }

        // 3. Buscar por email (kommo o cualquier email registrado)
        $emails = is_array($person->emails)
            ? $person->emails
            : json_decode($person->emails ?? '[]', true);

        foreach ($emails as $emailEntry) {
            $email = $emailEntry['value'] ?? null;
            if (! $email || str_ends_with($email, '@whatsapp.sofopolis.net')) {
                continue;
            }
            $results = $this->shareMeDataService->searchPatientByEmail($email);
            if (! empty($results)) {
                return $results[0]['_id'] ?? null;
            }
        }

        return null;
    }

    /** Caché por-request de atributos (evita N+1 en buildSmdPayload/buildNotes). */
    private function getAttribute(string $code): ?object
    {
        static $cache = [];

        return $cache[$code] ??= $this->attributeRepository->findOneByField('code', $code);
    }

    private function extractPhone($person): ?string
    {
        $contactNumbers = is_array($person->contact_numbers)
            ? $person->contact_numbers
            : json_decode($person->contact_numbers ?? '[]', true);

        $phone = $contactNumbers[0]['value'] ?? null;

        return ($phone && $phone !== '0') ? $phone : null;
    }

    /**
     * Construye el payload completo para SMD incluyendo:
     * - CI, nombre, teléfono
     * - Fecha de nacimiento aproximada (año actual - edad registrada en job_title)
     * - Seguro mapeado al índice SMD
     * - Resto de datos como nota
     */
    private function buildSmdPayload($person, ?string $phone): array
    {
        $nameParts = explode(' ', trim($person->name ?? 'Paciente'));

        // CI - usa caché estático (M-1)
        $ciAttr = $this->getAttribute('ci_paciente');
        $ci = $ciAttr ? $person->getCustomAttributeValue($ciAttr) : null;

        // Fecha de nacimiento aproximada desde edad (job_title = "Edad")
        // Aproximación: primer día del año de nacimiento. Margen ±1 año. (B-3)
        $age = is_numeric($person->job_title) ? (int) $person->job_title : null;
        $birthday = $age ? Carbon::now()->subYears($age)->startOfYear()->format('Y-m-d') : null;

        // Seguro → mapear nombre CRM al valor que espera SMD
        $insurance = null;
        $seguroAttr = $this->getAttribute('seguro_paciente');

        if ($seguroAttr) {
            /**
             * `seguro_paciente` es multiselect y se guarda como CSV de ids.
             * SMD solo acepta un seguro por paciente, así que mandamos el primero.
             */
            $seguroOptionIds = array_filter(array_map(
                'intval',
                explode(',', (string) $person->getCustomAttributeValue($seguroAttr))
            ));

            if (! empty($seguroOptionIds)) {
                $option = DB::table('attribute_options')
                    ->where('id', reset($seguroOptionIds))
                    ->first();

                if ($option) {
                    $insurance = self::INSURANCE_MAP[$option->name] ?? $option->name;
                }
            }
        }

        // Notas: datos adicionales sin campo directo en SMD
        $notes = $this->buildNotes($person);

        $payload = [
            'first_name' => $nameParts[0] ?? 'Paciente',
            'last_name'  => implode(' ', array_slice($nameParts, 1)) ?: 'Externo',
            'phone'      => $phone,
            'ci'         => $ci ?: null,
            'birthday'   => $birthday,
            'insurance'  => $insurance,
            'notes'      => $notes ?: null,
        ];

        return array_filter($payload, fn ($v) => $v !== null);
    }

    /**
     * Genera texto de notas con la información adicional del paciente que no
     * tiene campo directo en SMD.
     */
    private function buildNotes($person): string
    {
        $lines = [];

        // Estado del seguro
        $estadoAttr = $this->getAttribute('estado_seguro_paciente');
        if ($estadoAttr) {
            $estadoId = $person->getCustomAttributeValue($estadoAttr);
            if ($estadoId) {
                $estadoOpt = DB::table('attribute_options')->where('id', $estadoId)->first();
                if ($estadoOpt) {
                    $lines[] = 'Estado seguro: '.$estadoOpt->name;
                }
            }
        }

        // Doctor asignado
        $doctorAttr = $this->getAttribute('person_doctor');
        if ($doctorAttr) {
            $doctorId = $person->getCustomAttributeValue($doctorAttr);
            if ($doctorId) {
                $doctor = DB::table('doctors')->where('id', $doctorId)->value('name');
                if ($doctor) {
                    $lines[] = 'Doctor: '.$doctor;
                }
            }
        }

        // Organización
        if ($person->organization_id) {
            $org = DB::table('organizations')->where('id', $person->organization_id)->value('name');
            if ($org) {
                $lines[] = 'Organización: '.$org;
            }
        }

        return implode(' | ', $lines);
    }
}
