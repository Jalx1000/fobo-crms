<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Admin\Support\SmdTime;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\SpecialtyRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Tag\Repositories\TagRepository;

class IncomingAppointmentService
{
    /**
     * Catálogo de doctores memoizado por request, indexado para el matcheo de
     * asistentes. Clave '0'/'1' según se pidan todos o solo los activos.
     *
     * @var array<string, \Illuminate\Support\Collection>
     */
    private array $doctorCatalog = [];

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

        $externalId = $data['_id'] ?? null;

        if (! $externalId) {
            return $this->process($normalized);
        }

        // Idempotente por el _id del evento, con el mismo registro y el mismo
        // lock que el sync de Dropbox: un reintento del webhook ya no crea otra
        // cita (AUD-02), y si después llega el evento por Dropbox se actualiza
        // esta misma en vez de duplicarla.
        return \Illuminate\Support\Facades\Cache::lock('smd:event:'.$externalId, 60)
            ->block((int) config('smd.dropbox.lock_wait_seconds', 10), function () use ($normalized, $externalId, $data) {
                $existing = DB::table('smd_synced_events')->where('external_id', $externalId)->first();

                if ($existing) {
                    return ['lead_id' => $existing->lead_id, 'activity_id' => $existing->activity_id, 'duplicate' => true];
                }

                $result = $this->process($normalized);

                DB::table('smd_synced_events')->insert([
                    'external_id'  => $externalId,
                    'source_file'  => 'webhook',
                    'activity_id'  => $result['activity_id'] ?? null,
                    'lead_id'      => $result['lead_id'] ?? null,
                    'raw_payload'  => json_encode($data),
                    'status'       => '',
                    'processed_at' => now(),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                return $result;
            });
    }

    /**
     * Procesa payload en formato Dropbox (owner/attendances/startDate/endDate).
     *
     * Un evento sin paciente es un bloqueo de agenda: el doctor se reserva el
     * horario (pausas, casos especiales), no es una cita. No genera lead ni
     * paciente ni WhatsApp, pero SÍ se refleja en el calendario como una
     * actividad de ocupación (ver processBlockDropbox), para que el slot no se
     * vea libre.
     */
    public function processDropbox(array $data): array
    {
        $normalized = $this->normalizeDropbox($data);

        if (! $normalized['patient_identified']) {
            return $this->processBlockDropbox($normalized);
        }

        // Un bloqueo también puede venir CON asistente: SMD tapa los horarios
        // libres con un paciente inventado ("Ocupado Ocupado", sin teléfono ni
        // CI). Trae asistente, así que el corte de arriba no lo agarra y termina
        // creando persona + lead + cita por cada slot bloqueado.
        $directory = app(SmdPatientDirectory::class);

        if ($directory->isDummy(
            $directory->patient($normalized['patient_external_id'] ?? null),
            $normalized['patient_name'] ?? null,
        )) {
            return $this->processBlockDropbox($normalized);
        }

        return $this->process($normalized);
    }

    /**
     * Bloqueo de agenda: crea una actividad de ocupación atada SOLO al doctor.
     *
     * Es un `time_off` -el tipo que el calendario ya entiende como "no
     * disponible"- sin lead, sin paciente y sin conversación de WhatsApp. Solo
     * ocupa el horario para que no se vea libre. Sin doctor identificable no hay
     * agenda que ocupar, así que se descarta sin dejar rastro.
     */
    private function processBlockDropbox(array $normalized): array
    {
        $doctors = $normalized['doctor_identified'] ? $this->resolveDoctors($normalized) : [];

        if (empty($doctors)) {
            return ['skipped' => 'block'];
        }

        return DB::transaction(function () use ($normalized, $doctors) {
            $title = trim((string) ($normalized['summary'] ?? '')) ?: 'Bloqueo de agenda';

            $activity = $this->activityRepository->create([
                'type'          => 'time_off',
                'title'         => $title,
                'comment'       => $normalized['summary'] ?? '',
                // Ya vienen en hora local desde normalizeDropbox().
                'schedule_from' => $normalized['schedule_from'],
                'schedule_to'   => $normalized['schedule_to'],
                'user_id'       => 1,
                // Solo doctores: un bloqueo no tiene paciente.
                'participants'  => [
                    'doctors' => array_map(fn ($d) => $d->id, $doctors),
                ],
                'additional'    => json_encode([
                    'external_id' => $normalized['external_id'] ?? null,
                    'source'      => 'ShareMeData',
                    'is_block'    => true,
                ]),
            ]);

            return ['activity_id' => $activity->id, 'block' => true];
        });
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

            // Referencias obsoletas: el lead o la actividad trackeados fueron
            // borrados (limpiezas manuales, cancelaciones). El evento sigue en
            // SMD, así que se trata como si el lead no existiera y se recrea. Sin
            // esto, update() sobre un lead inexistente tira ModelNotFound y el
            // evento nunca vuelve a entrar.
            if ($leadId && ! $this->leadRepository->find($leadId)) {
                $leadId = null;
            }

            $activityExists = $activityId && $this->activityRepository->find($activityId);

            // Un bloqueo (sin lead) que ahora trae paciente deja de ser bloqueo:
            // se borra la actividad de ocupación y se crea la cita real -persona,
            // lead con su etapa y actividad meeting-. updateDropbox solo tocaba la
            // actividad, así que en esta transición el lead no nacía nunca. Cubre
            // también el lead obsoleto de arriba.
            if (! $leadId && $normalized['patient_identified']) {
                if ($activityExists) {
                    $this->activityRepository->delete($activityId);
                }

                Log::info('[IncomingAppointment] Cita (re)creada desde update (bloqueo con paciente o lead borrado)', [
                    'external_id' => $data['_id'],
                ]);

                return $this->process($normalized);
            }

            $activity = $activityExists ? $this->activityRepository->find($activityId) : null;

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
                    // Ya vienen en hora local desde normalizeDropbox().
                    'schedule_from' => $normalized['schedule_from'],
                    'schedule_to'   => $normalized['schedule_to'],
                    // Ambas claves siempre: el repositorio hace doctors()->sync([])
                    // cuando falta `doctors`, lo que borraría al médico de la cita.
                    'participants'  => [
                        'doctors' => $doctorIds,
                        'persons' => $personIds,
                    ],
                ], $activityId);
            } elseif ($leadId) {
                // El lead sigue pero la actividad fue borrada: sin esto la cita
                // desaparecía del calendario y el registro quedaba apuntando a un
                // id muerto (AUD-09). Se recrea atada al mismo lead.
                $lead = $this->leadRepository->find($leadId);
                $doctorIds = array_map(fn ($d) => $d->id, $doctors ?: $this->resolveDoctors($normalized));
                $personId = $person->id ?? $lead?->person_id;

                $activity = $this->activityRepository->create([
                    'type'          => 'meeting',
                    'title'         => $normalized['summary'] ?: ($lead->title ?? 'Cita SMD'),
                    'comment'       => $normalized['summary'] ?? '',
                    'schedule_from' => $normalized['schedule_from'],
                    'schedule_to'   => $normalized['schedule_to'],
                    'user_id'       => 1,
                    'participants'  => [
                        'doctors' => $doctorIds,
                        'persons' => array_filter([$personId]),
                    ],
                    'additional'    => json_encode([
                        'lead_id'     => $leadId,
                        'external_id' => $normalized['external_id'] ?? null,
                        'source'      => 'ShareMeData',
                    ]),
                ]);

                $activity->leads()->sync([$leadId]);
                $activityId = $activity->id;

                Log::info('[IncomingAppointment] Actividad borrada recreada desde SMD', [
                    'external_id' => $data['_id'],
                    'lead_id'     => $leadId,
                    'activity_id' => $activityId,
                ]);
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

                // Status vacío en SMD = "sin estado", no "volvió a consulta": no
                // baja una cita confirmada a Consultas. Pasaba con las citas del
                // formulario de WhatsApp cuando el evento quedaba sin estado.
                $keepsConfirmed = trim((string) $normalized['status']) === ''
                    && (int) DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id') === (int) config('smd.stage_map.confirmed');

                if ($stageId && ! $keepsConfirmed) {
                    $updateData['lead_pipeline_stage_id'] = $stageId;
                }

                if ($this->statusMapper->isCancelled($normalized['status'])) {
                    $updateData['status'] = 0;
                    $updateData['lost_reason'] = 'Cita '.$normalized['status'].' en SMD';
                    $updateData['closed_at'] = now();
                }

                // Reactivada en SMD: deshacer el cierre que había hecho el sync.
                // Solo si lo cerró el sync (motivo "… en SMD"): un cierre manual
                // en el CRM no se pisa (AUD-06).
                if (! $this->statusMapper->isCancelled($normalized['status']) && ! $normalized['archived']) {
                    $lead = $this->leadRepository->find($leadId);

                    if ($lead && $lead->status !== null && (int) $lead->status === 0
                        && str_ends_with((string) $lead->lost_reason, 'en SMD')) {
                        $updateData['status'] = null;
                        $updateData['lost_reason'] = null;
                        $updateData['closed_at'] = null;
                    }
                }

                if (! empty($updateData)) {
                    $this->leadRepository->update($updateData + ['entity_type' => 'leads'], $leadId);
                }

                $this->syncStatusTag($leadId, $normalized['status']);
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

            // Si el lead ya no existe (borrado a mano, o nunca se creó por ser un
            // bloqueo) no hay nada que cancelar: se evita el ModelNotFound.
            if ($leadId && ! $this->leadRepository->find($leadId)) {
                $leadId = null;
            }

            if ($leadId) {
                $cancelledStageId = (int) config('smd.stage_map.cancelled');

                $this->leadRepository->update([
                    'lead_pipeline_stage_id' => $cancelledStageId,
                    'status'                 => 0,
                    'lost_reason'            => 'Cita cancelada en SMD',
                    'closed_at'              => now(),
                    'entity_type'            => 'leads',
                ], $leadId);

                $this->syncStatusTag($leadId, 'cancelled');
            }

            // Bloqueo de agenda cancelado (sin lead): su `time_off` es lo único
            // que ocupa el horario, así que se borra. Antes quedaba y el doctor
            // seguía figurando ocupado (AUD-07). Solo se borra un time_off: una
            // cita (meeting) sin lead es un dato raro que no se toca a ciegas.
            if (! $leadId && $activityId) {
                $activity = $this->activityRepository->find($activityId);

                if ($activity && $activity->type === 'time_off') {
                    $this->activityRepository->delete($activityId);
                    DB::table('doctor_activities')->where('activity_id', $activityId)->delete();
                    $activityId = null;
                }
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

            $this->syncStatusTag($lead->id, $data['status'] ?? '');

            $activity = $this->activityRepository->create([
                'type'          => 'meeting',
                'title'         => $data['summary'] ?: ($person->name.' - '.($data['specialty'] ?? 'General')),
                'comment'       => $data['summary'] ?? '',
                // Camino compartido: desde Dropbox las horas ya llegan locales
                // (normalizeDropbox) y esto es un no-op; desde el webhook de SMD
                // (`slot.start`, formato no garantizado) sigue siendo la
                // conversión que se venía haciendo.
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
        // El catálogo se refresca por evento. Los archivos de Dropbox son
        // modificaciones SECUENCIALES, y un evento puede registrar un doctor que
        // el siguiente necesita reconocer. Cachearlo para toda la corrida hacía
        // que un evento posterior viera el catálogo viejo y clasificara al doctor
        // recién creado como paciente. Memoizado dentro del evento (knownDoctorIds
        // se llama dos veces), fresco entre eventos.
        $this->doctorCatalog = [];

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

        // Una cita puede tener varios médicos (ej. cirujano + ayudante). Un
        // asistente es médico si YA lo conocemos (por unique_id o nombre) O si el
        // payload lo tipa como physician/doctor. Es una UNIÓN, no una cadena de
        // fallback: antes, si un solo doctor conocido matcheaba, los demás
        // -identificados por `type`- se descartaban, y un "OCUPADO" con 8 médicos
        // donde solo uno estaba registrado quedaba con uno. Cubre además al médico
        // aún no registrado: resolveDoctor lo da de alta.
        $physiciansRaw = collect($attendances)
            ->filter(fn ($a) => (isset($a['_id']) && isset($activeDoctorIds[$a['_id']]))
                || in_array(strtolower($a['type'] ?? $a['virtualType'] ?? ''), ['physician', 'doctor']))
            ->values()
            ->all();

        // El `owner` también es médico de la cita cuando SMD lo tipa como
        // physician -NO un fallback-. Un evento puede traer un doctor en
        // `attendances` (ej. el ayudante) Y otro en `owner` (el tratante): ambos
        // deben quedar registrados. Antes el owner solo se usaba si `attendances`
        // no tenía ningún doctor, así que el segundo médico se perdía. El filtro
        // por `type` evita colgar la cita de quien solo creó el evento -la
        // recepcionista llega como `entity:staff`-. Se deduplica por `_id` para
        // no sumar dos veces al mismo si aparece en ambos lados.
        $owner = $data['owner'] ?? null;

        if (is_array($owner) && in_array(strtolower($owner['type'] ?? $owner['virtualType'] ?? ''), ['physician', 'doctor'])) {
            $ownerId = $owner['_id'] ?? null;

            $yaEsta = $ownerId && collect($physiciansRaw)->contains(fn ($p) => ($p['_id'] ?? null) === $ownerId);

            if (! $yaEsta) {
                $physiciansRaw[] = $owner;
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
            // SMD publica en UTC. La conversión a hora boliviana se hace ACÁ, en
            // el borde: de este punto para adentro las horas ya son locales y
            // nadie más tiene que acordarse de convertirlas.
            'schedule_from'       => isset($data['startDate']) ? SmdTime::toLocalString($data['startDate']) : null,
            'schedule_to'         => isset($data['endDate']) ? SmdTime::toLocalString($data['endDate']) : null,
            'specialty'           => 'General',
            'summary'             => $data['summary'] ?? '',
            'external_id'         => $data['_id'] ?? null,
            'archived'            => $data['archived'] ?? false,
            'status'              => $data['status'] ?? '',
        ];
    }

    /**
     * Ids de `attendances[]` que corresponden a doctores ya registrados,
     * indexados por id para un lookup directo.
     *
     * Reconoce al doctor por `unique_id` (SMD _id) O por NOMBRE contra la tabla
     * `doctors`. El match por nombre es lo que salva el caso real: SMD reasigna
     * los `_id` de los physicians cada tanto, así que el `unique_id` guardado se
     * desactualiza y un payload UPDATED -que no trae `type`- dejaba al doctor sin
     * reconocer y lo descartaba. El nombre no cambia, así que sigue matcheando.
     *
     * Con `$onlyActive` devuelve solo los que pueden atender: los recursos de SMD
     * (quirófano, recepcionista) viven en `doctors` desactivados para que sigan
     * siendo reconocibles sin que se les asignen citas.
     */
    private function knownDoctorIds(array $attendances, bool $onlyActive = false): array
    {
        $catalog = $this->doctorCatalog($onlyActive);

        $uids = $catalog->pluck('unique_id')->filter()->flip();
        $names = $catalog->mapWithKeys(fn ($d) => [$this->normalizeDoctorName($d->name) => true]);

        $result = [];

        foreach ($attendances as $a) {
            $id = is_array($a) ? ($a['_id'] ?? null) : null;

            if (! $id) {
                continue;
            }

            $name = $this->normalizeDoctorName(trim(($a['name'] ?? '').' '.($a['lastName'] ?? '')));

            if (isset($uids[$id]) || ($name !== '' && isset($names[$name]))) {
                $result[$id] = true;
            }
        }

        return $result;
    }

    /**
     * La tabla `doctors` cacheada por request (una consulta, no una por payload).
     */
    private function doctorCatalog(bool $onlyActive): \Illuminate\Support\Collection
    {
        $key = $onlyActive ? '1' : '0';

        return $this->doctorCatalog[$key] ??= DB::table('doctors')
            ->when($onlyActive, fn ($q) => $q->where('is_active', 1))
            ->get(['unique_id', 'name']);
    }

    /**
     * Doctor activo cuyo nombre contiene TODAS las palabras de `$name`
     * (normalizadas, sin títulos ni palabras de 1-2 letras). Null si ninguno
     * o si hay más de uno: con varios candidatos asignar sería adivinar.
     */
    private function doctorByNameTokens(string $name): ?object
    {
        $titles = ['dr', 'dra', 'doctor', 'doctora', 'od', 'lic'];

        $tokens = array_values(array_filter(
            explode(' ', $this->normalizeDoctorName($name)),
            fn ($t) => strlen($t) > 2 && ! in_array($t, $titles, true)
        ));

        if (! $tokens) {
            return null;
        }

        $candidates = DB::table('doctors')
            ->where('is_active', 1)
            ->get(['id', 'name'])
            ->filter(function ($d) use ($tokens) {
                $words = explode(' ', $this->normalizeDoctorName($d->name));

                return ! array_diff($tokens, $words);
            });

        if ($candidates->count() > 1) {
            Log::warning('[IncomingAppointment] Nombre de médico ambiguo, no se asigna a ninguno', [
                'name'       => $name,
                'candidatos' => $candidates->pluck('name')->all(),
            ]);

            return null;
        }

        return $candidates->isNotEmpty() ? $this->doctorRepository->find($candidates->first()->id) : null;
    }

    /**
     * Nombre de doctor normalizado para comparar: sin acentos, sin puntuación,
     * en minúsculas y con espacios colapsados. "Carolina Rojas Godoy" y
     * "CAROLINA  ROJAS GODOY" caen en la misma clave.
     */
    private function normalizeDoctorName(?string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', (string) $name) ?: (string) $name;

        return preg_replace('/\s+/', ' ', trim(strtolower(preg_replace('/[^a-zA-Z ]/', '', $ascii))));
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

        // 3. Nombre aproximado: TODAS las palabras del nombre que llega (sin
        // títulos) tienen que estar en el del doctor, y un solo doctor puede
        // cumplirlo. Cubre "Dra. Carolina Rojas" ↔ "Carolina Rojas Godoy".
        // Antes bastaba UNA palabra (`LIKE %parte%`): un médico nuevo que
        // compartía el nombre de pila quedaba asignado a otro doctor (AUD-08).
        if (! $doctor && $name !== '') {
            $doctor = $this->doctorByNameTokens($name);
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

    /**
     * El contacto del CRM para el paciente de una cita.
     *
     * El orden importa y es lo que evita duplicados y mezclas:
     *
     * 1. `smd_patient_id` - identidad exacta y estable. Va PRIMERO. Antes se
     *    buscaba por teléfono primero y, si aparecía alguien, se le estampaba el
     *    id de SMD. Como la mitad de los números corresponde a más de un paciente
     *    (familias que comparten celular: medido, 20 de 40 conversaciones reales),
     *    eso le pegaba el id de un hermano a la ficha del otro.
     * 2. Teléfono + nombre. El teléfono solo no alcanza justamente por lo
     *    anterior, así que se exige que además coincida el nombre.
     * 3. Alta nueva.
     *
     * Y el teléfono se carga SIEMPRE, también cuando el paciente ya existía. La
     * rama por `smd_patient_id` devolvía la ficha tal cual, así que un paciente
     * dado de alta por un payload sin teléfono se quedaba sin número para
     * siempre aunque los payloads siguientes sí lo trajeran: de ahí salían los
     * contactos sin número que nunca recibían el recordatorio. Si el payload no
     * lo trae, se pide a la API de pacientes de SMD.
     */
    private function resolvePerson(?string $phone, ?string $smdPatientId, string $name): object
    {
        // Salida de emergencia. Esto corre en el camino de TODA cita que entra,
        // así que apagarlo tiene que ser una variable de entorno y no una
        // edición de urgencia con las citas llegando.
        if (! config('smd.dedupe_v2', true)) {
            return $this->resolvePersonLegacy($phone, $smdPatientId, $name);
        }

        $directory = app(SmdPatientDirectory::class);

        // 1. Identidad exacta por el id que vino del calendario.
        if ($smdPatientId) {
            $person = $this->personRepository->findOneByField('smd_patient_id', $smdPatientId);

            if ($person) {
                // A SMD sólo se le pregunta si el dato falta de los dos lados.
                // Preguntarle siempre costaba una llamada HTTP (~0,9 s) por cita
                // para confirmar un teléfono que ya teníamos: es la diferencia
                // entre una llamada por PACIENTE NUEVO y una por EVENTO.
                if (! $phone && empty($person->contact_numbers)) {
                    $phone = $directory->phone($smdPatientId);
                }

                $this->backfillPhone($person, $phone);

                return $person;
            }
        }

        // 2. Teléfono + nombre, antes de tocar la red: el payload trae el
        // teléfono en el 98% de los eventos con paciente, así que casi siempre
        // alcanza para reconocerlo sin consultar a SMD.
        if ($phone) {
            if ($person = $this->personByPhoneAndName($phone, $name)) {
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

        // 3. Recién ahora la red: es un paciente que no pudimos identificar con
        // lo que ya teníamos. La ficha real se busca por id y, si no aparece,
        // por nombre - el `_id` del asistente del calendario no siempre es el de
        // la ficha de paciente.
        $ficha = $directory->resolve($smdPatientId, $name);

        if ($ficha) {
            // El id REAL manda: es el que sirve para volver a consultar la ficha
            // y el que evita duplicar cuando el calendario mande otro.
            $idReal = $ficha['_id'] ?? $smdPatientId;

            if ($idReal !== $smdPatientId) {
                $person = $this->personRepository->findOneByField('smd_patient_id', $idReal);

                if ($person) {
                    $this->backfillPhone($person, $phone ?: $directory->phone($idReal));

                    return $person;
                }
            }

            $smdPatientId = $idReal;

            $delaFicha = $ficha['phone'] ?? null;

            if (is_array($delaFicha)) {
                $delaFicha = $delaFicha[0] ?? null;
            }

            $delaFicha = preg_replace('/\D/', '', (string) $delaFicha);

            $nuevoTelefono = $phone === null && $delaFicha !== '';

            $phone = $phone ?: ($delaFicha !== '' ? $delaFicha : null);

            // El teléfono lo aportó SMD, así que el intento del paso 2 se hizo
            // sin él: se reintenta ahora que sí lo tenemos, o si no duplicamos
            // al paciente que ya estaba cargado sin número.
            if ($nuevoTelefono && $person = $this->personByPhoneAndName($phone, $name)) {
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

        // 4. Alta nueva.
        $createData = [
            'name'        => $name ?: 'Paciente Externo',
            'entity_type' => 'persons',
        ];

        if ($phone) {
            $createData['contact_numbers'] = [['value' => $phone, 'label' => 'work']];
            $createData['emails'] = [['value' => $phone.'@whatsapp.sofopolis.net', 'label' => 'work']];
        }

        if ($smdPatientId) {
            $createData['smd_patient_id'] = $smdPatientId;
        }

        return $this->personRepository->create($createData);
    }

    /**
     * Comportamiento anterior a `smd.dedupe_v2`, conservado tal cual.
     *
     * Busca por teléfono primero y le estampa el `smd_patient_id` a quien
     * encuentre. Con familias que comparten celular eso cruza las identidades
     * entre los dos sistemas - es exactamente el motivo por el que existe la
     * versión nueva. Está acá sólo para poder volver atrás sin editar código.
     */
    private function resolvePersonLegacy(?string $phone, ?string $smdPatientId, string $name): object
    {
        if ($phone) {
            $cleanPhone = ltrim(preg_replace('/[^0-9]/', '', $phone), '0');
            $kommoEmail = $cleanPhone.'@whatsapp.sofopolis.net';

            $person = $this->personRepository->whereJsonContains('emails', [['value' => $kommoEmail]])->first()
                ?? $this->personRepository->whereJsonContains('contact_numbers', [['value' => $phone]])->first();

            if ($person) {
                if (! ($person instanceof \Webkul\Contact\Contracts\Person)) {
                    $person = $this->personRepository->find($person->id);
                }

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

        if ($smdPatientId) {
            $person = $this->personRepository->findOneByField('smd_patient_id', $smdPatientId);

            if ($person) {
                return $person;
            }
        }

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

    /**
     * Busca por número y confirma con el nombre.
     *
     * El prefiltro es un LIKE por los últimos 8 dígitos porque el CRM guarda
     * tanto "78164851" como "59178164851" y una comparación exacta no cruzaría
     * los dos formatos -ése era el otro origen de fichas duplicadas-. La
     * confirmación final se hace en PHP contra los valores normalizados.
     */
    private function personByPhoneAndName(string $phone, string $name): ?object
    {
        $tail = substr(preg_replace('/\D/', '', $phone), -8);

        if (strlen($tail) < 8 || trim($name) === '') {
            return null;
        }

        $candidates = $this->personRepository
            ->where('contact_numbers', 'like', '%'.$tail.'%')
            ->get();

        foreach ($candidates as $candidate) {
            $matchesPhone = false;

            foreach ((array) $candidate->contact_numbers as $number) {
                $value = is_array($number) ? ($number['value'] ?? '') : $number;

                if (str_ends_with(preg_replace('/\D/', '', (string) $value), $tail)) {
                    $matchesPhone = true;

                    break;
                }
            }

            if ($matchesPhone && $this->sameName($candidate->name, $name)) {
                return $this->personRepository->find($candidate->id);
            }
        }

        return null;
    }

    /**
     * ¿Son la misma persona? Compara nombres sin acentos, mayúsculas ni los
     * sufijos que SMD arrastra en el nombre ("(SISTEMA 2025) SO POLIZA VENCE…").
     */
    private function sameName(?string $a, ?string $b): bool
    {
        $normalize = function (?string $value): string {
            $value = explode(' (', (string) $value)[0];
            $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;

            return preg_replace('/\s+/', ' ', trim(strtolower(preg_replace('/[^a-zA-Z ]/', '', $value))));
        };

        $a = $normalize($a);
        $b = $normalize($b);

        return $a !== '' && $a === $b;
    }

    /**
     * Carga el número en una ficha que no lo tenía, sin tocar los que ya están.
     */
    private function backfillPhone(object $person, ?string $phone): void
    {
        if (! $phone || ! empty($person->contact_numbers)) {
            return;
        }

        $this->personRepository->update([
            'entity_type'     => 'persons',
            'contact_numbers' => [['value' => $phone, 'label' => 'work']],
        ], $person->id, ['contact_numbers']);

        Log::info('[SMD] Teléfono cargado desde la API de pacientes', [
            'person_id'      => $person->id,
            'smd_patient_id' => $person->smd_patient_id,
        ]);
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

    /**
     * Deja el lead con el tag que corresponde al status de SMD y sin los otros
     * que administra la sincronización.
     *
     * Es un sync, no un attach: una cita que fue "Cita perdida" y se reprograma
     * tiene que perder esa marca, si no queda mal etiquetada para siempre. Los
     * tags puestos a mano no se tocan - solo se sacan los de managedTags().
     */
    private function syncStatusTag(?int $leadId, ?string $smdStatus): void
    {
        if (! $leadId) {
            return;
        }

        $tagName = $this->statusMapper->getTag($smdStatus);

        $lead = $this->leadRepository->find($leadId);

        if (! $lead) {
            return;
        }

        $sobrantes = array_filter(
            $this->statusMapper->managedTags(),
            fn ($nombre) => $nombre !== $tagName
        );

        $idsASacar = $lead->tags()->whereIn('name', $sobrantes)->pluck('tags.id')->all();

        if ($idsASacar) {
            $lead->tags()->detach($idsASacar);
        }

        if ($tagName) {
            $this->attachTagToLead($leadId, $tagName, $this->statusMapper->getTagColor($smdStatus));
        }
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
