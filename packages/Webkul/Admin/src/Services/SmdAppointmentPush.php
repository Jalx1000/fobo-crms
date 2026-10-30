<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Repositories\PersonRepository;

/**
 * Empuja una cita del CRM a SMD (PATCH del evento) con paciente, médicos,
 * horario y estado. Movido tal cual desde ActivityController para que lo usen
 * también los formularios de WhatsApp de cancelar y reprogramar.
 */
class SmdAppointmentPush
{
    public function __construct(
        protected ActivityRepository $activityRepository,
        protected PersonRepository $personRepository,
    ) {}

    /**
     * Arma el evento en formato SMD desde la cita del CRM y lo manda por PATCH.
     *
     * Solo empuja si la cita está vinculada a un evento de SMD (external_id). Una
     * cita 100% local todavía no existe en SMD, así que no hay qué editar allá.
     *
     * @return array{synced: bool, reason: ?string}
     */
    public function push(int $activityId): array
    {
        $event = DB::table('smd_synced_events')->where('activity_id', $activityId)->first();

        if (! $event || ! $event->external_id) {
            return ['synced' => false, 'reason' => 'la cita no está vinculada a SMD'];
        }

        $activity = $this->activityRepository->find($activityId);
        $payload = json_decode($event->raw_payload ?? '{}', true) ?: [];

        // Paciente: su smd_patient_id es la clave con la que SMD lo reconoce. Si
        // aún no existe en SMD (p.ej. al editar la cita y cambiarlo por uno nuevo),
        // se lo crea allá primero para poder incluirlo en el evento.
        $attendances = [];
        $personId = $activity->participants->pluck('person_id')->filter()->first();
        $person = $personId ? $this->personRepository->find($personId) : null;

        if ($person) {
            $smdPatientId = $person->smd_patient_id;

            if (! $smdPatientId) {
                $smdPatientId = $this->ensureSmdPatientId($person);

                // ensureSmdPatientId pudo completar nombre/teléfono en local:
                // relee la persona para que el attendance use los datos frescos.
                if ($smdPatientId) {
                    $person = $this->personRepository->find($personId) ?: $person;
                }
            }

            if ($smdPatientId) {
                [$nombre, $apellido] = $this->splitName($person->name);
                $phone = '';

                foreach ((array) $person->contact_numbers as $n) {
                    $val = is_array($n) ? ($n['value'] ?? '') : $n;
                    if ($val !== '') {
                        $phone = (string) $val;
                        break;
                    }
                }

                $attendances[] = [
                    '_id'      => $smdPatientId,
                    'name'     => $nombre,
                    'lastName' => $apellido,
                    'type'     => 'patient',
                    'phone'    => $phone,
                ];
            }
        }

        // Todos los doctores de la cita (por su unique_id = _id de SMD).
        foreach ($activity->doctors as $doctor) {
            if (! $doctor->unique_id) {
                continue;
            }

            [$nombre, $apellido] = $this->splitName($doctor->name);

            $attendances[] = [
                '_id'      => $doctor->unique_id,
                'name'     => $nombre,
                'lastName' => $apellido,
                'type'     => 'physician',
                'phone'    => '',
            ];
        }

        $lead = $activity->leads()->first();
        $status = app(\Webkul\Admin\Services\SmdStatusMapper::class)
            ->smdStatusForStage($lead ? (int) $lead->lead_pipeline_stage_id : null);

        $body = [
            '_id'         => $event->external_id,
            'eventId'     => $payload['eventId'] ?? null,
            'type'        => 'event',
            'summary'     => (string) ($activity->title ?? ''),
            'description' => (string) ($activity->comment ?? ''),
            // El horario se guarda en hora local; SMD lo espera en UTC.
            'startDate'   => Carbon::parse($activity->schedule_from, config('app.timezone'))->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'endDate'     => Carbon::parse($activity->schedule_to, config('app.timezone'))->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'attendances' => $attendances,
            'status'      => $status,
        ];

        $result = app(\Webkul\Admin\Services\ShareMeDataService::class)->updateEvent($event->external_id, $body);

        return [
            'synced' => (bool) ($result['success'] ?? false),
            'reason' => $result['message'] ?? null,
        ];
    }

    /**
     * Asegura que la persona exista en SMD y devuelve su smd_patient_id.
     *
     * Si ya lo tiene, lo devuelve tal cual. Si no, lo busca en SMD por teléfono
     * en DOS variantes -tal como está guardado (local, 8 díg.) y con código país-
     * porque SMD guarda el número de cualquiera de las dos formas y el match es
     * por regex de substring. Si aparece, ADOPTA ese paciente (guarda su _id) y
     * completa en local los datos que falten (nombre, emails, teléfono) con los de
     * SMD. Si no aparece, lo crea en SMD. Devuelve null si no hay teléfono válido
     * o si SMD falló (en ese caso el paciente no se adjunta al evento, como antes).
     */
    protected function ensureSmdPatientId($person): ?string
    {
        if ($person->smd_patient_id) {
            return $person->smd_patient_id;
        }

        // Teléfono local: primer contact_number con valor.
        $phone = '';
        foreach ((array) $person->contact_numbers as $n) {
            $val = is_array($n) ? ($n['value'] ?? '') : $n;
            if ($val !== '') {
                $phone = (string) $val;
                break;
            }
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        // Variantes a buscar, en AMBAS direcciones: tal cual, con código país y
        // sin código país. SMD guarda el número de cualquiera de las dos formas y
        // el match es por regex de substring, así que se cubren las dos. Dedup.
        $cc = preg_replace('/\D+/', '', (string) config('smd.whatsapp_country_code', '591'));
        $phoneVariants = [$digits];

        if ($cc !== '' && str_starts_with($digits, $cc) && strlen($digits) > strlen($cc)) {
            $phoneVariants[] = substr($digits, strlen($cc)); // sin código país
        }

        if ($cc !== '' && strlen($digits) === 8 && ! str_starts_with($digits, $cc)) {
            $phoneVariants[] = $cc.$digits; // con código país
        }

        $phoneVariants = array_values(array_unique(array_filter($phoneVariants)));

        $smd = app(\Webkul\Admin\Services\ShareMeDataService::class);

        // 1) Buscar en SMD por cada variante; la primera coincidencia gana.
        $found = null;
        foreach ($phoneVariants as $variant) {
            $matches = $smd->searchPatient($variant);
            if (! empty($matches)) {
                $found = $matches[0];
                break;
            }
        }

        if ($found && ! empty($found['_id'])) {
            $smdPersonId = (string) $found['_id'];

            // Gap-fill: completar SOLO lo que falte en local con el dato de SMD.
            $fill = [];

            if (trim((string) $person->name) === '') {
                $smdName = trim((string) ($found['fullName']
                    ?? trim(($found['name'] ?? '').' '.($found['lastName'] ?? ''))));
                if ($smdName !== '') {
                    $fill['name'] = $smdName;
                }
            }

            if (empty($person->emails) && ! empty($found['secondEmail'])) {
                $fill['emails'] = json_encode([['value' => (string) $found['secondEmail'], 'label' => 'work']]);
            }

            if (empty($person->contact_numbers) && ! empty($found['phone'])) {
                $fill['contact_numbers'] = json_encode([['value' => (string) $found['phone'], 'label' => 'work']]);
            }

            $fill['smd_patient_id'] = $smdPersonId;
            DB::table('persons')->where('id', $person->id)->update($fill);

            return $smdPersonId;
        }

        // 2) No existe en SMD: crearlo (con teléfono en código país, como el resto).
        [$nombre, $apellido] = $this->splitName($person->name ?: 'Paciente');
        $ciAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
            ->findOneByField('code', 'ci_paciente');
        $ci = $ciAttr ? $person->getCustomAttributeValue($ciAttr) : null;

        // Al crear, se registra con código país (como el resto de los pacientes).
        $createPhone = (strlen($digits) === 8 && $cc !== '' && ! str_starts_with($digits, $cc))
            ? $cc.$digits
            : $digits;

        $result = $smd->createPatient([
            'first_name' => $nombre ?: 'Paciente',
            'last_name'  => $apellido ?: 'Externo',
            'phone'      => $createPhone,
            'ci'         => $ci ?: null,
        ]);

        $smdPersonId = null;
        if ($result['success'] ?? false) {
            $smdPersonId = $result['data']['_id'] ?? null;
        } elseif ($result['duplicate'] ?? false) {
            // SMD dice que ya existe: re-buscar y adoptar.
            foreach ($phoneVariants as $variant) {
                $retry = $smd->searchPatient($variant);
                if (! empty($retry[0]['_id'])) {
                    $smdPersonId = (string) $retry[0]['_id'];
                    break;
                }
            }
        }

        if ($smdPersonId) {
            DB::table('persons')->where('id', $person->id)->update(['smd_patient_id' => $smdPersonId]);
        }

        return $smdPersonId;
    }

    /**
     * Parte un nombre completo en [nombre, apellido] para el formato de SMD.
     */
    protected function splitName(?string $full): array
    {
        $full = trim((string) $full);

        if ($full === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $full, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
