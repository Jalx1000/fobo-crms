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
        protected DoctorRepository    $doctorRepository,
        protected SpecialtyRepository $specialtyRepository,
        protected PersonRepository    $personRepository,
        protected LeadRepository      $leadRepository,
        protected ActivityRepository  $activityRepository,
        protected TagRepository       $tagRepository,
        protected SmdStatusMapper     $statusMapper,
    ) {}

    /**
     * Procesa payload en formato Webhook (physician/patient/slot).
     */
    public function processWebhook(array $data): array
    {
        $normalized = [
            'doctor_external_id'  => $data['physician']['_id'],
            'doctor_name'         => trim(($data['physician']['name'] ?? '').' '.($data['physician']['lastName'] ?? '')),
            'doctor_email'        => $data['physician']['email'] ?? null,
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
        $leadId     = $existing->lead_id;

        if (! $activityId) {
            Log::warning('[IncomingAppointment] updateDropbox sin activity_id, creando nuevo', [
                'external_id' => $data['_id'],
            ]);

            return $this->processDropbox($data);
        }

        return DB::transaction(function () use ($data, $activityId, $leadId) {
            $normalized = $this->normalizeDropbox($data);

            $activity = $this->activityRepository->find($activityId);

            if ($activity) {
                $this->activityRepository->update([
                    'title'         => $normalized['summary'] ?: $activity->title,
                    'comment'       => $normalized['summary'] ?? $activity->comment,
                    'schedule_from' => Carbon::parse($normalized['schedule_from'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    'schedule_to'   => Carbon::parse($normalized['schedule_to'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                ], $activityId);
            }

            if ($leadId) {
                $updateData = [];

                if ($normalized['summary']) {
                    $updateData['description'] = $normalized['summary'];
                }

                $stageId = $this->statusMapper->toLeadStageId($normalized['status']);

                if ($stageId) {
                    $updateData['lead_pipeline_stage_id'] = $stageId;
                }

                if ($this->statusMapper->isCancelled($normalized['status'])) {
                    $updateData['status']      = 0;
                    $updateData['lost_reason'] = 'Cita '.$normalized['status'].' en SMD';
                    $updateData['closed_at']   = now();
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
            $leadId     = $existing->lead_id;
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
            $doctor    = $this->resolveDoctor($data);
            $specialty = $this->specialtyRepository->fetchOrCreateByName($data['specialty'] ?? 'General');

            if (! $doctor->specialties->contains($specialty->id)) {
                $doctor->specialties()->attach($specialty->id);
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
                'participants'  => ['doctors' => [$doctor->id], 'persons' => [$person->id]],
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

        // Doctor: buscar en attendances el de type "physician"
        $physicianRaw = collect($attendances)
            ->first(fn ($a) => in_array(strtolower($a['type'] ?? $a['virtualType'] ?? ''), ['physician', 'doctor']));

        // Fallback: usar owner si no hay physician en attendances
        if (! $physicianRaw) {
            $owner        = $this->parseOwner($data['owner'] ?? null);
            $physicianRaw = $owner['_id'] ? ['_id' => $owner['_id'], 'name' => $owner['name'], 'lastName' => $owner['lastName']] : null;
        }

        $physician = $physicianRaw ? $this->parseAttendance($physicianRaw) : ['_id' => null, 'name' => 'Doctor SMD', 'lastName' => '', 'phone' => null, 'birthday' => null];

        // Paciente: primer attendance de type "patient" (o el que no sea physician)
        $patientRaw = collect($attendances)
            ->first(fn ($a) => in_array(strtolower($a['type'] ?? $a['virtualType'] ?? ''), ['patient']));

        // Fallback: primer attendance que no sea el physician
        if (! $patientRaw) {
            $patientRaw = collect($attendances)
                ->first(fn ($a) => ($a['_id'] ?? null) !== ($physician['_id'] ?? null));
        }

        $patient = $patientRaw ? $this->parseAttendance($patientRaw) : ['_id' => null, 'name' => 'Paciente', 'lastName' => '', 'phone' => null, 'birthday' => null];

        return [
            'doctor_external_id'  => $physician['_id'],
            'doctor_name'         => trim(($physician['name'] ?? '').' '.($physician['lastName'] ?? '')),
            'patient_name'        => trim(($patient['name'] ?? 'Paciente').' '.($patient['lastName'] ?? '')),
            'patient_phone'       => $patient['phone'],
            'patient_external_id' => $patient['_id'],
            'patient_birthday'    => $patient['birthday'],
            'schedule_from'       => $data['startDate']  ?? null,
            'schedule_to'         => $data['endDate']    ?? null,
            'specialty'           => 'General',
            'summary'             => $data['summary']    ?? '',
            'external_id'         => $data['_id']        ?? null,
            'archived'            => $data['archived']   ?? false,
            'status'              => $data['status']     ?? '',
        ];
    }

    /**
     * owner puede ser objeto O string (solo el _id) en eventos cancelados.
     */
    private function parseOwner(mixed $owner): array
    {
        if (is_string($owner)) {
            return ['_id' => $owner, 'name' => null, 'lastName' => null, 'phone' => null];
        }

        if (is_array($owner)) {
            return [
                '_id'      => $owner['_id']      ?? null,
                'name'     => $owner['name']     ?? null,
                'lastName' => $owner['lastName'] ?? null,
                'phone'    => $owner['phone']    ?? null,
            ];
        }

        return ['_id' => null, 'name' => null, 'lastName' => null, 'phone' => null];
    }

    private function parseAttendance(array $attendance): array
    {
        return [
            'name'     => $attendance['name']     ?? 'Paciente',
            'lastName' => $attendance['lastName'] ?? '',
            'phone'    => $attendance['phone']    ?? null,
            'birthday' => $attendance['birthday'] ?? null,
            '_id'      => $attendance['_id']      ?? null,
        ];
    }

    private function resolveDoctor(array $data): object
    {
        // 1. Buscar por unique_id (SMD _id)
        $doctor = $data['doctor_external_id']
            ? $this->doctorRepository->findOneByField('unique_id', $data['doctor_external_id'])
            : null;

        // 2. Buscar por nombre completo exacto
        if (! $doctor && ! empty($data['doctor_name'])) {
            $doctor = $this->doctorRepository->findOneByField('name', trim($data['doctor_name']));
        }

        // 3. Buscar por nombre parcial en BD
        if (! $doctor && ! empty($data['doctor_name'])) {
            $nameParts = array_filter(explode(' ', trim($data['doctor_name'])));
            foreach ($nameParts as $part) {
                if (strlen($part) <= 2) continue;
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
                'name'      => $data['doctor_name'] ?: 'Doctor SMD',
                'unique_id' => $data['doctor_external_id'],
                'is_active' => true,
            ]);

            Log::info('[IncomingAppointment] Doctor creado desde SMD', [
                'name'      => $doctor->name,
                'unique_id' => $data['doctor_external_id'],
            ]);
        }

        // Guardar unique_id si aún no lo tiene (para futuros lookups)
        if ($data['doctor_external_id'] && ! $doctor->unique_id) {
            $this->doctorRepository->update(['unique_id' => $data['doctor_external_id']], $doctor->id);
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
