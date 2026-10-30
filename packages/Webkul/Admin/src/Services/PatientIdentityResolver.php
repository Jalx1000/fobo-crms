<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Whatsapp\Services\SmdPatientLink;

/**
 * Identidad del paciente cuando quien agenda es OTRA persona (padre, madre,
 * tutor, familiar) y vínculo paciente ↔ responsable.
 *
 * La clave es la CÉDULA (decisión de negocio, ver
 * planning/14-pacientes-terceros/00.diseno.md): el teléfono de quien escribe no
 * identifica al paciente, y dos padres desde números distintos tienen que
 * llegar a la MISMA ficha del hijo.
 *
 * Se separa en dos pasos a propósito:
 *  - lookup(): solo consulta (CRM → SMD). Lo usa la pantalla de datos del Flow.
 *  - commit(): da de alta y vincula. Se llama recién al confirmar, así un
 *    formulario abandonado no deja fichas sueltas.
 */
class PatientIdentityResolver
{
    /** Siglas de expedición que a veces se escriben pegadas a la cédula ("1234567 LP"). */
    protected const EXPEDIDO = ['LP', 'SC', 'CB', 'OR', 'PT', 'CH', 'TJ', 'BN', 'PD', 'PA', 'BE'];

    public function __construct(
        protected PersonRepository $personRepository,
        protected ShareMeDataService $smd,
    ) {}

    /**
     * Pacientes ya vinculados a este responsable.
     *
     * @return array<int, array{id:int, name:string, ci:?string, relation:?string}>
     */
    public function patientsOf(int $responsibleId): array
    {
        $ciAttribute = $this->ciAttributeId();

        return DB::table('person_relations')
            ->join('persons', 'persons.id', '=', 'person_relations.patient_person_id')
            ->where('person_relations.responsible_person_id', $responsibleId)
            ->orderBy('persons.name')
            ->get(['persons.id', 'persons.name', 'person_relations.relation'])
            ->map(fn ($row) => [
                'id'       => (int) $row->id,
                'name'     => (string) $row->name,
                'ci'       => $ciAttribute ? $this->ciOf((int) $row->id, $ciAttribute) : null,
                'relation' => $row->relation,
            ])
            ->all();
    }

    /**
     * Busca al paciente por cédula, SIN escribir nada.
     *
     * @return array{status:string, ci:string, person_id?:int, smd_patient?:array, display_name?:string}
     *                                                                                                   status: crm | smd | new | mismatch | invalid
     */
    public function lookup(string $ci, string $name): array
    {
        $ci = $this->normalizeCi($ci);

        if (strlen(preg_replace('/\D/', '', $ci)) < 5 || trim($name) === '') {
            return ['status' => 'invalid', 'ci' => $ci];
        }

        // 1. En el CRM, por el atributo ci_paciente.
        if ($person = $this->personByCi($ci)) {
            return $this->sameName($person->name, $name)
                ? ['status' => 'crm', 'ci' => $ci, 'person_id' => (int) $person->id, 'display_name' => $person->name]
                : ['status' => 'mismatch', 'ci' => $ci];
        }

        // 2. En SMD, por personID (que en SMD ES la cédula).
        $patient = $this->smdPatientByCi($ci);

        if ($patient) {
            $smdName = $patient['fullName'] ?? trim(($patient['name'] ?? '').' '.($patient['lastName'] ?? ''));

            if (! $this->sameName($smdName, $name)) {
                return ['status' => 'mismatch', 'ci' => $ci];
            }

            // Puede que ya esté en el CRM vinculado por smd_patient_id, pero sin
            // la cédula cargada: es la misma ficha, no otra.
            $existing = ! empty($patient['_id'])
                ? DB::table('persons')->where('smd_patient_id', $patient['_id'])->first(['id', 'name'])
                : null;

            if ($existing) {
                return ['status' => 'crm', 'ci' => $ci, 'person_id' => (int) $existing->id, 'display_name' => $existing->name, 'smd_patient' => $patient];
            }

            return ['status' => 'smd', 'ci' => $ci, 'smd_patient' => $patient, 'display_name' => explode(' (', $smdName)[0]];
        }

        // 3. No está en ningún lado: será un paciente nuevo.
        return ['status' => 'new', 'ci' => $ci, 'display_name' => trim($name)];
    }

    /**
     * Resuelve (lookup) y deja el resultado escrito: alta si hace falta y
     * vínculo con el responsable.
     *
     * @param  array{ci:string, name:string, birthdate?:?string, relation?:?string}  $input
     * @return array{status:string, patient_id?:int, patient_name?:string}
     *                                                                     status: found | created | self | mismatch | invalid
     */
    public function commit(int $responsibleId, array $input, string $source = 'whatsapp_flow', ?array $found = null): array
    {
        // $found: el lookup ya hecho en una pantalla anterior. Repetirlo vuelve a
        // consultar SMD, y en el formulario eso pasaba el tiempo que espera Meta.
        $found ??= $this->lookup((string) ($input['ci'] ?? ''), (string) ($input['name'] ?? ''));

        if (in_array($found['status'], ['mismatch', 'invalid'], true)) {
            return ['status' => $found['status']];
        }

        $relation = $this->normalizeRelation($input['relation'] ?? null);

        return DB::transaction(function () use ($found, $input, $responsibleId, $relation, $source) {
            if ($found['status'] === 'crm') {
                $patientId = $found['person_id'];
                $this->backfillCi($patientId, $found['ci']);
                $status = 'found';
            } else {
                $patientId = $this->createPatient($found, $input);
                $status = 'created';
            }

            if ($patientId === $responsibleId) {
                return ['status' => 'self', 'patient_id' => $patientId, 'patient_name' => $found['display_name'] ?? ''];
            }

            $this->link($patientId, $responsibleId, $relation, $source);

            return [
                'status'       => $status,
                'patient_id'   => $patientId,
                'patient_name' => (string) DB::table('persons')->where('id', $patientId)->value('name'),
            ];
        });
    }

    /**
     * Vincula paciente y responsable. Idempotente: el segundo padre se suma, no
     * duplica, y repetir el mismo vínculo solo actualiza la relación.
     */
    public function link(int $patientId, int $responsibleId, ?string $relation, string $source): void
    {
        if ($patientId === $responsibleId) {
            return;
        }

        $exists = DB::table('person_relations')
            ->where('patient_person_id', $patientId)
            ->where('responsible_person_id', $responsibleId)
            ->exists();

        if ($exists) {
            if ($relation) {
                DB::table('person_relations')
                    ->where('patient_person_id', $patientId)
                    ->where('responsible_person_id', $responsibleId)
                    ->update(['relation' => $relation, 'updated_at' => now()]);
            }

            return;
        }

        DB::table('person_relations')->insert([
            'patient_person_id'     => $patientId,
            'responsible_person_id' => $responsibleId,
            'relation'              => $relation,
            'source'                => $source,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        Log::info('[Pacientes] Responsable vinculado', [
            'patient_id'     => $patientId,
            'responsible_id' => $responsibleId,
            'relation'       => $relation,
            'source'         => $source,
        ]);
    }

    /**
     * La cédula cargada de una persona, o null.
     */
    public function ciFor(int $personId): ?string
    {
        $attribute = $this->ciAttributeId();

        return $attribute ? $this->ciOf($personId, $attribute) : null;
    }

    /**
     * Reemplaza la cédula de la ficha (el paciente la corrigió en el formulario,
     * después de que el formulario la validó contra su nombre).
     */
    public function replaceCi(int $personId, string $ci): void
    {
        $attribute = $this->ciAttributeId();
        $ci = $this->normalizeCi($ci);
        $previous = $attribute ? $this->ciOf($personId, $attribute) : null;

        if (! $attribute || $ci === '' || $previous === $ci) {
            return;
        }

        DB::table('attribute_values')->updateOrInsert(
            ['attribute_id' => $attribute, 'entity_id' => $personId, 'entity_type' => 'persons'],
            ['text_value' => $ci],
        );

        Log::info('[Pacientes] Cédula corregida por el paciente en el formulario', [
            'person_id' => $personId,
            'antes'     => $previous ? '***'.substr($previous, -3) : null,
            'ahora'     => '***'.substr($ci, -3),
        ]);
    }

    /**
     * Le asigna la cédula a un paciente que ya elegimos (quien escribe, o uno a
     * su cargo) y que no la tenía. Con la cédula se verifica el seguro.
     *
     * Si esa cédula ya es de OTRA ficha del CRM con el mismo nombre, es la misma
     * persona registrada dos veces (ej. la ficha que vino de SMD y el contacto
     * de WhatsApp): se agenda en esa ficha, que es la que tiene el historial, y
     * se la vincula a quien escribe para que pueda ver, cancelar o mover la cita.
     *
     * @param  ?string  $fullName  nombre que escribió (el del contacto suele ser el
     *                             perfil de WhatsApp: "Javi 🦷" no coincide con nada)
     * @param  bool  $write  false = solo validar (pantallas previas a confirmar)
     * @return array{status:string, patient_id?:int, ci?:string, display_name?:string}
     *                                                                                 status: ok | invalid | mismatch
     */
    public function claimCi(int $personId, int $responsibleId, string $ci, ?string $fullName = null, bool $write = true, ?array $found = null): array
    {
        $current = (string) DB::table('persons')->where('id', $personId)->value('name');
        $name = trim((string) $fullName) ?: $current;
        $found ??= $this->lookup($ci, $name);

        if (in_array($found['status'], ['invalid', 'mismatch'], true)) {
            return ['status' => $found['status']];
        }

        $patientId = $found['status'] === 'crm' ? $found['person_id'] : $personId;

        // La cédula de quien escribe no puede ser la de alguien a su cargo.
        if ($patientId === $responsibleId && $personId !== $responsibleId) {
            return ['status' => 'mismatch'];
        }

        if ($write) {
            DB::transaction(function () use ($found, $patientId, $personId, $responsibleId, $name, $current) {
                $this->backfillCi($patientId, $found['ci']);

                // Paciente nuevo para todos: su nombre real reemplaza al del perfil
                // de WhatsApp (con ese nombre se lo da de alta en SMD al agendar).
                if ($found['status'] === 'new' && ! $this->sameName($name, $current)) {
                    DB::table('persons')->where('id', $patientId)->update(['name' => $name, 'updated_at' => now()]);
                }

                if ($found['status'] === 'smd' && ! empty($found['smd_patient']['_id'])) {
                    DB::table('persons')->where('id', $patientId)
                        ->where(fn ($q) => $q->whereNull('smd_patient_id')->orWhere('smd_patient_id', ''))
                        ->update(['smd_patient_id' => $found['smd_patient']['_id']]);
                }

                if ($patientId !== $personId) {
                    $this->link($patientId, $responsibleId, null, 'whatsapp_flow_ci');
                }
            });
        }

        return [
            'status'       => 'ok',
            'patient_id'   => $patientId,
            'ci'           => $found['ci'],
            'display_name' => explode(' (', (string) ($found['display_name'] ?? $name))[0],
            'found'        => $found,
        ];
    }

    /**
     * Cédula comparable: mayúsculas, sin puntos ni espacios, sin la sigla de
     * expedición. Conserva el complemento ("1234567-1A").
     */
    public function normalizeCi(string $ci): string
    {
        $ci = strtoupper(trim($ci));
        $ci = preg_replace('/\s+('.implode('|', self::EXPEDIDO).')\.?$/', '', $ci);

        return preg_replace('/[^A-Z0-9\-]/', '', $ci);
    }

    /**
     * ¿Es la misma persona? Las palabras de un nombre tienen que estar todas en
     * el otro (en cualquier sentido): "Juan Pérez" ↔ "JUAN PEREZ LOPEZ (SISTEMA
     * 2024)". Sin acentos, mayúsculas ni el sufijo de SMD.
     */
    public function sameName(?string $a, ?string $b): bool
    {
        $words = function (?string $value): array {
            $value = explode(' (', (string) $value)[0];
            $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;
            $value = strtolower(preg_replace('/[^a-zA-Z ]/', '', $value));

            return array_values(array_filter(explode(' ', $value), fn ($w) => strlen($w) >= 3));
        };

        $wa = $words($a);
        $wb = $words($b);

        if (! $wa || ! $wb) {
            return false;
        }

        return ! array_diff($wa, $wb) || ! array_diff($wb, $wa);
    }

    protected function personByCi(string $ci): ?object
    {
        $attribute = $this->ciAttributeId();

        if (! $attribute) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $ci);

        // Prefiltro por los dígitos y confirmación exacta en PHP: en el CRM la
        // cédula se cargó a mano con formatos distintos ("1234567 LP", "1.234.567").
        $match = DB::table('attribute_values')
            ->where('attribute_id', $attribute)
            ->where('entity_type', 'persons')
            ->where('text_value', 'like', '%'.$digits.'%')
            ->get(['entity_id', 'text_value'])
            ->first(fn ($row) => $this->normalizeCi((string) $row->text_value) === $ci);

        return $match ? DB::table('persons')->where('id', $match->entity_id)->first(['id', 'name']) : null;
    }

    protected function smdPatientByCi(string $ci): ?array
    {
        try {
            $candidates = $this->smd->searchPatientByCi(preg_replace('/\D/', '', $ci));
        } catch (Throwable $e) {
            return null;
        }

        $exact = array_values(array_filter(
            (array) $candidates,
            fn ($p) => $this->normalizeCi((string) ($p['personID'] ?? '')) === $ci
        ));

        return count($exact) === 1 ? $exact[0] : null;
    }

    protected function createPatient(array $found, array $input): int
    {
        $data = isset($found['smd_patient'])
            ? app(SmdPatientLink::class)->attributesFrom($found['smd_patient'])
            : ['name' => trim((string) $input['name'])];

        $data['ci_paciente'] = $found['ci'];

        if (empty($data['fecha_nacimiento']) && ! empty($input['birthdate'])) {
            $data['fecha_nacimiento'] = substr((string) $input['birthdate'], 0, 10);
        }

        // Sin teléfono propio a propósito: se lo alcanza por sus responsables.
        // Copiar el número del padre es lo que vuelve ambiguos los números.
        $person = $this->personRepository->create(array_merge($data, [
            'entity_type'     => 'persons',
            'emails'          => [],
            'contact_numbers' => [],
        ]));

        Log::info('[Pacientes] Paciente tercero creado', [
            'person_id'      => $person->id,
            'smd_patient_id' => $data['smd_patient_id'] ?? null,
            'origen'         => isset($found['smd_patient']) ? 'smd' : 'nuevo',
        ]);

        return (int) $person->id;
    }

    protected function backfillCi(int $personId, string $ci): void
    {
        $attribute = $this->ciAttributeId();

        if (! $attribute || $this->ciOf($personId, $attribute)) {
            return;
        }

        // updateOrInsert: puede existir la fila con el valor vacío.
        DB::table('attribute_values')->updateOrInsert(
            ['attribute_id' => $attribute, 'entity_id' => $personId, 'entity_type' => 'persons'],
            ['text_value' => $ci],
        );
    }

    protected function normalizeRelation(?string $relation): ?string
    {
        $relation = strtolower(trim((string) $relation));

        // Qué es el paciente para el responsable (ver PatientFlow::RELATIONS).
        return in_array($relation, ['hijo', 'padre_madre', 'pareja', 'hermano', 'familiar', 'otro'], true) ? $relation : ($relation === '' ? null : 'otro');
    }

    protected function ciOf(int $personId, int $attribute): ?string
    {
        $value = DB::table('attribute_values')
            ->where('attribute_id', $attribute)
            ->where('entity_type', 'persons')
            ->where('entity_id', $personId)
            ->value('text_value');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    protected function ciAttributeId(): ?int
    {
        static $id = false;

        if ($id === false) {
            $id = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');
        }

        return $id ? (int) $id : null;
    }
}
