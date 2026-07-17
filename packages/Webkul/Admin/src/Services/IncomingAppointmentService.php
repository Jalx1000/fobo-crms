<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\SpecialtyRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Tag\Repositories\TagRepository;

class IncomingAppointmentService
{
    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected SpecialtyRepository $specialtyRepository,
        protected PersonRepository $personRepository,
        protected LeadRepository $leadRepository,
        protected ActivityRepository $activityRepository,
        protected TagRepository $tagRepository,
        protected SmdStatusMapper $statusMapper,
    ) {}

    /**
     * Procesa payload en formato Webhook (physician/patient/slot).
     */
    public function processWebhook(array $data): array
    {
        $normalized = [
            'doctors' => [[
                '_id'   => $data['physician']['_id'],
                'name'  => trim(($data['physician']['name'] ?? '').' '.($data['physician']['lastName'] ?? '')),
                'email' => $data['physician']['email'] ?? null,
            ]],
            'patient_name'        => ($data['patient']['name'] ?? 'Paciente').' '.($data['patient']['lastName'] ?? ''),
            'patient_phone'       => $data['patient']['phone'],
            'patient_external_id' => null,
            'schedule_from'       => $data['slot']['start'],
            'schedule_to'         => $data['slot']['end'],
            'specialty'           => $data['specialty'] ?? 'General',
            'summary'             => $data['summary'] ?? '',
            'external_id'         => $data['_id'] ?? null,
            'status'              => '',
        ];

        return $this->process($normalized);
    }

    /**
     * Procesa payload en formato Dropbox (owner/attendances/startDate/endDate).
     */
    public function processDropbox(array $data): array
    {
        return $this->process($this->normalizeDropbox($data));
    }

    /**
     * Actualiza una cita existente en el CRM a partir de un JSON de Dropbox modificado.
     */
    public function updateDropbox(array $data, object $existing): array
    {
        $activityId = $existing->activity_id;
        $leadId = $existing->lead_id;

        if (! $activityId) {
            Log::warning('[IncomingAppointment] updateDropbox sin activity_id, creando nuevo', [
                'external_id' => $data['_id'],
            ]);

            return $this->processDropbox($data);
        }

        return DB::transaction(function () use ($data, $activityId, $leadId) {
            $normalized = $this->normalizeDropbox($data);

            $activity = $this->activityRepository->find($activityId);

            // Si en SMD cambian el paciente o el profesional, la cita del CRM debe
            // seguirlos. resolvePerson() da de alta al paciente si todavía no está
            // registrado, y resolveDoctor() busca al médico por unique_id y, si no,
            // por nombre. Solo se resuelven cuando el payload los identifica: un
            // payload sin médico/paciente reconocible no debe pisar a los actuales.
            $doctors = $normalized['doctor_identified'] ? $this->resolveDoctors($normalized) : [];

            $person = $normalized['patient_identified']
                ? $this->resolvePerson(
                    $normalized['patient_phone'] ?? null,
                    $normalized['patient_external_id'] ?? null,
                    $normalized['patient_name']
                )
                : null;

            if ($activity) {
                $doctorIds = $doctors
                    ? array_map(fn ($d) => $d->id, $doctors)
                    : $activity->doctors->pluck('id')->all();

                $personIds = $person
                    ? [$person->id]
                    : $activity->participants->pluck('person_id')->filter()->values()->all();

                $this->activityRepository->update([
                    'title'         => $normalized['summary'] ?: $activity->title,
                    'comment'       => $normalized['summary'] ?? $activity->comment,
                    'schedule_from' => Carbon::parse($normalized['schedule_from'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    'schedule_to'   => Carbon::parse($normalized['schedule_to'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    // Ambas claves siempre: el repositorio hace doctors()->sync([])
                    // cuando falta `doctors`, lo que borraría al médico de la cita.
                    'participants'  => [
                        'doctors' => $doctorIds,
                        'persons' => $personIds,
                    ],
                ], $activityId);
            }

            if ($leadId) {
                $updateData = [];

                // El lead debe apuntar al paciente vigente si en SMD lo cambiaron.
                if ($person) {
                    $updateData['person'] = ['id' => $person->id];
                }

                if ($normalized['summary']) {
                    $updateData['description'] = $normalized['summary'];
                }

                $stageId = $this->statusMapper->toLeadStageId($normalized['status']);

                if ($stageId) {
                    $updateData['lead_pipeline_stage_id'] = $stageId;
                }

                if ($this->statusMapper->isCancelled($normalized['status'])) {
                    $updateData['status'] = 0;
                    $updateData['lost_reason'] = 'Cita '.$normalized['status'].' en SMD';
                    $updateData['closed_at'] = now();
                }

                if (! empty($updateData)) {
                    $this->leadRepository->update($updateData + ['entity_type' => 'leads'], $leadId);
                }
            }

            Log::info('[IncomingAppointment] Cita actualizada', [
                'external_id' => $data['_id'],
                'activity_id' => $activityId,
            ]);

            return ['lead_id' => $leadId, 'activity_id' => $activityId, 'action' => 'updated'];
        });
    }

    /**
     * Cancela una cita en el CRM cuando llega con archived:true o status CANCELED desde SMD.
     */
    public function cancelDropbox(string $externalId, object $existing): array
    {
        return DB::transaction(function () use ($externalId, $existing) {
            $leadId = $existing->lead_id;
            $activityId = $existing->activity_id;

            if ($leadId) {
                $cancelledStageId = (int) config('smd.stage_map.cancelled');

                $this->leadRepository->update([
                    'lead_pipeline_stage_id' => $cancelledStageId,
                    'status'                 => 0,
                    'lost_reason'            => 'Cita cancelada en SMD',
                    'closed_at'              => now(),
                    'entity_type'            => 'leads',
                ], $leadId);

                $this->attachTagToLead($leadId, 'Cancelado', '#ef4444');
            }

            Log::info('[IncomingAppointment] Cita cancelada desde SMD', [
                'external_id' => $externalId,
                'lead_id'     => $leadId,
            ]);

            return ['lead_id' => $leadId, 'activity_id' => $activityId, 'action' => 'cancelled'];
        });
    }

    private function process(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $doctors = $this->resolveDoctors($data);
            $specialty = $this->specialtyRepository->fetchOrCreateByName($data['specialty'] ?? 'General');

            foreach ($doctors as $doctor) {
                if (! $doctor->specialties->contains($specialty->id)) {
                    $doctor->specialties()->attach($specialty->id);
                }
            }

            $person = $this->resolvePerson(
                $data['patient_phone'] ?? null,
                $data['patient_external_id'] ?? null,
                $data['patient_name']
            );

            $lead = $this->createLead($person, $data);

            $stageId = $this->statusMapper->toLeadStageId($data['status'] ?? '');

            if ($stageId) {
                $this->leadRepository->update([
                    'lead_pipeline_stage_id' => $stageId,
                    'entity_type'            => 'leads',
                ], $lead->id);
            }

            $activity = $this->activityRepository->create([
                'type'          => 'meeting',
                'title'         => $data['summary'] ?: ($person->name.' - '.($data['specialty'] ?? 'General')),
                'comment'       => $data['summary'] ?? '',
                'schedule_from' => Carbon::parse($data['schedule_from'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'schedule_to'   => Carbon::parse($data['schedule_to'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'user_id'       => 1,
                'participants'  => [
                    'doctors' => array_map(fn ($d) => $d->id, $doctors),
                    'persons' => [$person->id],
                ],
                'additional'    => json_encode([
                    'lead_id'     => $lead->id,
                    'external_id' => $data['external_id'],
                    'source'      => 'ShareMeData',
                ]),
            ]);

            $activity->leads()->sync([$lead->id]);

            Log::info('[IncomingAppointment] Cita procesada', [
                'lead_id'     => $lead->id,
                'activity_id' => $activity->id,
                'external_id' => $data['external_id'],
            ]);

            return ['lead_id' => $lead->id, 'activity_id' => $activity->id];
        });
    }

    private function normalizeDropbox(array $data): array
    {
        $attendances = $data['attendances'] ?? [];

        // Los payloads UPDATED de SMD vienen deshidratados: no traen `type` ni
        // `phone` (verificado sobre datos reales: 45 de 52 asistentes sin `type`).
        // El `_id` del asistente sí es estable y coincide con doctors.unique_id,
        // así que el médico se identifica cruzando ids contra la tabla y no por
        // `type`, que en la mayoría de los payloads no existe.
        // Dos conjuntos con propósitos distintos:
        // - `$allDoctorIds` (activos o no) marca qué asistentes NO son el paciente.
        //   Incluye recursos como el quirófano o la recepcionista, que están en
        //   `doctors` pero desactivados: hay que reconocerlos para no confundirlos
        //   con el paciente.
        // - `$activeDoctorIds` son los que pueden atender la cita.
        $allDoctorIds = $this->knownDoctorIds($attendances);
        $activeDoctorIds = $this->knownDoctorIds($attendances, true);

        // Una cita puede tener varios médicos (ej. cirujano + ayudante), así que
        // se toman todos los asistentes que sean doctores activos, no solo el primero.
        $physiciansRaw = collect($attendances)
            ->filter(fn ($a) => isset($a['_id']) && isset($activeDoctorIds[$a['_id']]))
            ->values()
            ->all();

        // Fallback: `type` explícito. Cubre los CREATED hidratados y al médico que
        // todavía no está registrado en el CRM (resolveDoctor lo dará de alta).
        if (! $physiciansRaw) {
            $physiciansRaw = collect($attendances)
                ->filter(fn ($a) => in_array(strtolower($a['type'] ?? $a['virtualType'] ?? ''), ['physician', 'doctor']))
                ->values()
                ->all();
        }

        // Último fallback: el `owner`, y SOLO si SMD lo tipa como physician.
        // Muchos payloads UPDATED traen únicamente al paciente en `attendances` y
        // al médico en `owner` (verificado sobre datos reales: rescata 57 de las 60
        // citas diarias que caían en el placeholder). El filtro por `type` es lo que
        // evita colgar la cita de quien solo creó el evento —la recepcionista llega
        // como `entity:staff`—, que fue el motivo por el que antes se descartaba
        // `owner` por completo.
        if (! $physiciansRaw) {
            $owner = $data['owner'] ?? null;

            if (is_array($owner) && in_array(strtolower($owner['type'] ?? $owner['virtualType'] ?? ''), ['physician', 'doctor'])) {
                $physiciansRaw = [$owner];
            }
        }

        $physicians = array_map(fn ($p) => $this->parseAttendance($p), $physiciansRaw);
        $physicianIds = array_values(array_filter(array_map(fn ($p) => $p['_id'], $physicians)));

        // Paciente: primer attendance de type "patient"
        $patientRaw = collect($attendances)
            ->first(fn ($a) => in_array(strtolower($a['type'] ?? $a['virtualType'] ?? ''), ['patient']));

        // Fallback: el primero que no sea uno de los médicos elegidos ni otro doctor conocido.
        if (! $patientRaw) {
            $patientRaw = collect($attendances)
                ->first(fn ($a) => ! in_array($a['_id'] ?? null, $physicianIds, true)
                    && ! isset($allDoctorIds[$a['_id'] ?? '']));
        }

        $patient = $patientRaw ? $this->parseAttendance($patientRaw) : ['_id' => null, 'name' => 'Paciente', 'lastName' => '', 'phone' => null, 'birthday' => null];

        return [
            // Distinguen "el payload trae este actor" de "usamos el placeholder".
            // updateDropbox() solo reasigna participantes cuando son identificables;
            // si no, conserva los que ya tiene la cita en vez de pisarlos con
            // "Doctor SMD" / "Paciente".
            'doctor_identified'   => (bool) $physiciansRaw,
            'patient_identified'  => (bool) $patientRaw,
            'doctors'             => array_map(fn ($p) => [
                '_id'  => $p['_id'],
                'name' => trim(($p['name'] ?? '').' '.($p['lastName'] ?? '')),
            ], $physicians),
            'patient_name'        => trim(($patient['name'] ?? 'Paciente').' '.($patient['lastName'] ?? '')),
            'patient_phone'       => $patient['phone'],
            'patient_external_id' => $patient['_id'],
            'patient_birthday'    => $patient['birthday'],
            'schedule_from'       => $data['startDate'] ?? null,
            'schedule_to'         => $data['endDate'] ?? null,
            'specialty'           => 'General',
            'summary'             => $data['summary'] ?? '',
            'external_id'         => $data['_id'] ?? null,
            'archived'            => $data['archived'] ?? false,
            'status'              => $data['status'] ?? '',
        ];
    }

    /**
     * Ids de `attendances[]` que corresponden a doctores ya registrados
     * (doctors.unique_id), indexados por id para un lookup directo.
     *
     * Es lo que permite identificar al médico en los payloads UPDATED, que no
     * traen `type`. Una sola consulta por payload.
     *
     * Con `$onlyActive` devuelve solo los que pueden atender: los recursos de SMD
     * (quirófano, recepcionista) viven en `doctors` desactivados para que sigan
     * siendo reconocibles sin que se les asignen citas.
     */
    private function knownDoctorIds(array $attendances, bool $onlyActive = false): array
    {
        $ids = array_values(array_filter(array_map(
            fn ($a) => is_array($a) ? ($a['_id'] ?? null) : null,
            $attendances
        )));

        if (! $ids) {
            return [];
        }

        return DB::table('doctors')
            ->whereIn('unique_id', $ids)
            ->when($onlyActive, fn ($q) => $q->where('is_active', 1))
            ->pluck('unique_id')
            ->flip()
            ->all();
    }

    private function parseAttendance(array $attendance): array
    {
        return [
            'name'     => $attendance['name'] ?? 'Paciente',
            'lastName' => $attendance['lastName'] ?? '',
            'phone'    => $attendance['phone'] ?? null,
            'birthday' => $attendance['birthday'] ?? null,
            '_id'      => $attendance['_id'] ?? null,
        ];
    }

    /**
     * Resuelve todos los médicos del payload. Una cita puede tener más de uno.
     *
     * Si el payload no identificó a ninguno se cae al placeholder "Doctor SMD":
     * la cita debe existir igual, aunque quede pendiente de asignar.
     *
     * @return array<int, object>
     */
    private function resolveDoctors(array $data): array
    {
        $resolved = [];

        foreach ($data['doctors'] ?? [] as $raw) {
            $doctor = $this->resolveDoctor($raw);

            // Indexado por id: dos entradas del payload pueden resolver al mismo
            // doctor (ej. una por `_id` y otra por nombre) y sync() no debe duplicar.
            $resolved[$doctor->id] = $doctor;
        }

        if (! $resolved) {
            $placeholder = $this->resolveDoctor(['_id' => null, 'name' => 'Doctor SMD']);

            $resolved[$placeholder->id] = $placeholder;
        }

        return array_values($resolved);
    }

    /**
     * @param  array{_id: ?string, name: ?string}  $data
     */
    private function resolveDoctor(array $data): object
    {
        $externalId = $data['_id'] ?? null;
        $name = trim($data['name'] ?? '');

        // 1. Buscar por unique_id (SMD _id)
        $doctor = $externalId
            ? $this->doctorRepository->findOneByField('unique_id', $externalId)
            : null;

        // 2. Buscar por nombre completo exacto
        if (! $doctor && $name !== '') {
            $doctor = $this->doctorRepository->findOneByField('name', $name);
        }

        // 3. Buscar por nombre parcial en BD
        if (! $doctor && $name !== '') {
            $nameParts = array_filter(explode(' ', $name));
            foreach ($nameParts as $part) {
                if (strlen($part) <= 2) {
                    continue;
                }
                $found = DB::table('doctors')
                    ->where('name', 'like', "%{$part}%")
                    ->where('is_active', 1)
                    ->first();
                if ($found) {
                    $doctor = $this->doctorRepository->find($found->id);
                    break;
                }
            }
        }

        // 4. Crear doctor con datos de SMD si no existe
        if (! $doctor) {
            $doctor = $this->doctorRepository->create([
                'name'      => $name ?: 'Doctor SMD',
                'unique_id' => $externalId,
                'is_active' => true,
            ]);

            Log::info('[IncomingAppointment] Doctor creado desde SMD', [
                'name'      => $doctor->name,
                'unique_id' => $externalId,
            ]);
        }

        // Guardar unique_id si aún no lo tiene (para futuros lookups)
        if ($externalId && ! $doctor->unique_id) {
            $this->doctorRepository->update(['unique_id' => $externalId], $doctor->id);
            $doctor = $this->doctorRepository->find($doctor->id);
        }

        return $doctor;
    }

    private function resolvePerson(?string $phone, ?string $smdPatientId, string $name): object
    {
        // Buscar por teléfono primero
        if ($phone) {
            $cleanPhone = ltrim(preg_replace('/[^0-9]/', '', $phone), '0');
            $kommoEmail = $cleanPhone.'@whatsapp.sofopolis.net';

            $person = $this->personRepository->whereJsonContains('emails', [['value' => $kommoEmail]])->first()
                ?? $this->personRepository->whereJsonContains('contact_numbers', [['value' => $phone]])->first();

            if ($person) {
                if (! ($person instanceof \Webkul\Contact\Contracts\Person)) {
                    $person = $this->personRepository->find($person->id);
                }

                // Guardar el id de SMD si todavía no lo tiene: los payloads UPDATED
                // vienen sin `phone`, así que sin esto una modificación posterior no
                // encontraría a este paciente y crearía un duplicado.
                if ($smdPatientId && ! $person->smd_patient_id) {
                    $this->personRepository->update([
                        'smd_patient_id' => $smdPatientId,
                        'entity_type'    => 'persons',
                    ], $person->id);

                    $person = $this->personRepository->find($person->id);
                }

                return $person;
            }
        }

        // Buscar por smd_patient_id si no hay teléfono o no se encontró
        if ($smdPatientId) {
            $person = $this->personRepository->findOneByField('smd_patient_id', $smdPatientId);

            if ($person) {
                return $person;
            }
        }

        // Crear nuevo paciente
        $createData = [
            'name'        => $name ?: 'Paciente Externo',
            'entity_type' => 'persons',
        ];

        if ($phone) {
            $cleanPhone = ltrim(preg_replace('/[^0-9]/', '', $phone), '0');
            $createData['contact_numbers'] = [['value' => $phone, 'label' => 'work']];
            if ($cleanPhone) {
                $createData['emails'] = [['value' => $cleanPhone.'@whatsapp.sofopolis.net', 'label' => 'work']];
            }
        }

        if ($smdPatientId) {
            $createData['smd_patient_id'] = $smdPatientId;
        }

        return $this->personRepository->create($createData);
    }

    private function createLead(object $person, array $data): object
    {
        $leadData = [
            'title'       => ($person->name ?? 'Paciente').' - '.($data['specialty'] ?? 'General'),
            'description' => $data['summary'] ?: 'Cita sincronizada desde ShareMeData',
            'entity_type' => 'leads',
            'person'      => ['id' => $person->id],
        ];

        return $this->leadRepository->create($leadData);
    }

    private function attachTagToLead(int $leadId, string $tagName, string $color = '#6b7280'): void
    {
        $tag = $this->tagRepository->findOneByField('name', $tagName);

        if (! $tag) {
            $tag = $this->tagRepository->create([
                'name'    => $tagName,
                'color'   => $color,
                'user_id' => 1,
            ]);
        }

        $lead = $this->leadRepository->find($leadId);

        if ($lead) {
            $lead->tags()->syncWithoutDetaching([$tag->id]);
        }
    }
}
