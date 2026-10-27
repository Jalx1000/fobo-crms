<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Builds CONTRATO A: the patient-context block the CRM injects into the agent
 * event, so the agent knows the patient's full appointment history WITHOUT
 * calling get_citas on every turn.
 *
 * Option B data model: each LEAD is one appointment (cita). The lead-conversación
 * (the Consultas-stage lead anchored by conversations.lead_id) is NOT a cita and
 * is excluded from the history. See config('whatsapp.crm').
 */
class PatientContext
{
    /**
     * @return array{
     *   person_id: ?int, paciente_nuevo: bool, nombre_registrado: ?string,
     *   ci_paciente_registrada: ?string, seguro_registrado: ?string,
     *   citas: array<int, array>, cita_activa: ?int
     * }
     */
    public function build(Conversation $conversation): array
    {
        $person = $conversation->person;

        if (! $person) {
            return $this->empty();
        }

        $consultaStage = (int) config('whatsapp.crm.stage_consulta', 1);
        $labels = (array) config('whatsapp.crm.stage_labels', []);
        $activaStage = (int) config('whatsapp.crm.stage_activa', 7);

        try {
            // Todas las personas de ese número, no sólo la ligada al chat. En la
            // clínica la madre agenda para toda la familia desde un solo celular:
            // Loyi y Matías comparten el 59170895092 y son fichas distintas. Con
            // una sola persona el agente contestaba con las citas de quien
            // hubiera quedado enganchado al chat -elegido arbitrariamente por
            // orden de id-, o sea le respondía a uno con la cita del otro.
            $personas = $this->personasDelNumero($conversation, $person);

            // Y los pacientes a cargo (hijos, familiares agendados por esta
            // persona): no comparten número -no tienen teléfono propio-, así
            // que sin esto sus citas no aparecerían en el contexto del agente.
            $aCargo = $this->pacientesACargo($person->id);

            if ($aCargo->isNotEmpty()) {
                $personas = $personas->merge($aCargo)->unique('id')->values();
            }

            // Every lead of the person is a candidate cita. Exclude the
            // conversación lead (Consultas). Oldest→newest for a readable history.
            $leads = LeadProxy::modelClass()::query()
                ->whereIn('person_id', $personas->pluck('id')->all())
                ->orderBy('id')
                ->get()
                ->filter(fn ($lead) => (int) $lead->lead_pipeline_stage_id !== $consultaStage)
                ->values();
        } catch (Throwable $e) {
            // Never let a context-build failure drop the agent event - degrade to
            // "no history" and let the agent fall back to get_citas on demand.
            Log::warning('WhatsApp: could not build patient context', [
                'person_id' => $person->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->empty($person->id, $person->name);
        }

        // Nombre por persona, para que cada cita diga de quién es. Sin esto el
        // agente ve dos citas del mismo día y no puede distinguirlas.
        $nombres = $personas->pluck('name', 'id');

        $citas = $leads->map(function ($lead) use ($labels, $nombres) {
            [$fecha, $hora] = $this->fechaHora($lead);

            return [
                'lead_id'  => $lead->id,
                'paciente' => $nombres[$lead->person_id] ?? null,
                'fecha'    => $fecha,
                'hora'     => $hora,
                'doctor'   => $this->leadAttribute($lead, 'cita_doctor'),
                'servicio' => $this->servicio($lead),
                'estado'   => $labels[(int) $lead->lead_pipeline_stage_id] ?? 'desconocido',
            ];
        })->values();

        // The vigente appointment: a lead in the "confirmada" stage. If several,
        // the most recent wins.
        $activa = $leads->where('lead_pipeline_stage_id', $activaStage)->last();

        // CI/seguro live on the lead (copied per cita). Read them from the active
        // cita, or the latest one, so a returning patient is pre-filled.
        $source = $activa ?? $leads->last();

        return [
            'person_id'              => $person->id,
            // Varios pacientes en el mismo número: el agente tiene que preguntar
            // para quién es antes de dar por sentado a uno.
            'pacientes_del_numero'   => $personas->count() > 1
                ? $personas->map(fn ($p) => ['person_id' => $p->id, 'nombre' => $p->name])->values()->all()
                : [],
            // Personas que este contacto agenda (hijos, familiares): el agente
            // los ofrece como opción; para un tercero nuevo, enviar el Flow
            // "paciente_tercero" (POST /conversations/{id}/flows).
            'pacientes_a_cargo'      => ($aCargo ?? collect())
                ->map(fn ($p) => ['person_id' => $p->id, 'nombre' => $p->name, 'relacion' => $p->relation ?? null])
                ->values()->all(),
            'paciente_nuevo'         => $citas->isEmpty(),
            'nombre_registrado'      => $person->name ?: null,
            'ci_paciente_registrada' => $source ? $this->leadAttribute($source, 'ci') : null,
            'seguro_registrado'      => $source ? $this->leadAttribute($source, 'seguro') : null,
            'citas'                  => $citas->all(),
            'cita_activa'            => $activa?->id,
            'recordatorios'          => $this->recordatorios($conversation),
        ];
    }

    /**
     * Recordatorios mandados a este chat de citas que todavía no pasaron, con
     * lo que contestó el paciente (null = sin respuesta). Un "Sí" suelto que
     * llega después de uno sin respuesta es la confirmación de esa cita.
     */
    protected function recordatorios(Conversation $conversation): array
    {
        try {
            return app(\Webkul\Admin\Services\PatientAppointments::class)->remindersOf((int) $conversation->id);
        } catch (Throwable $e) {
            Log::warning('WhatsApp: could not load reminders for the agent', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Todas las fichas que comparten el número de la conversación.
     *
     * Siempre incluye a la persona ligada al chat, aunque la conversación no
     * tenga teléfono cargado: el contexto nunca puede quedar más pobre que antes.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function personasDelNumero(Conversation $conversation, $person)
    {
        $tail = PhoneNumber::tail($conversation->wa_phone);

        if (strlen($tail) < 8) {
            return collect([$person]);
        }

        $personas = PersonProxy::modelClass()::where('contact_numbers', 'like', '%'.$tail.'%')
            ->get()
            ->filter(function ($candidata) use ($tail) {
                foreach ((array) $candidata->contact_numbers as $numero) {
                    $valor = is_array($numero) ? ($numero['value'] ?? '') : $numero;

                    if (str_ends_with(PhoneNumber::digits((string) $valor), $tail)) {
                        return true;
                    }
                }

                return false;
            });

        if (! $personas->contains(fn ($p) => $p->id === $person->id)) {
            $personas->push($person);
        }

        return $personas->sortBy('id')->values();
    }

    /**
     * The service name for a cita, taken from the lead's products.
     */
    protected function servicio($lead): ?string
    {
        try {
            $product = $lead->products?->first();

            return $product?->name ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Split the appointment datetime attribute into ["DD/MM/YYYY", "HH:MM"].
     *
     * Reads the `cita_fecha` custom attribute (Decisión B2). Accepts a date or a
     * datetime string; returns [null, null] if unset or unparseable.
     */
    protected function fechaHora($lead): array
    {
        $raw = $this->leadAttribute($lead, 'cita_fecha');

        if (! $raw) {
            return [null, null];
        }

        try {
            $dt = new \DateTimeImmutable((string) $raw);

            return [$dt->format('d/m/Y'), $dt->format('H:i')];
        } catch (Throwable) {
            return [(string) $raw, null];
        }
    }

    /**
     * Read a lead custom attribute by its configured code.
     *
     * The codes are instance-specific and live in config('whatsapp.crm.attributes').
     * Krayin exposes custom attribute values on the entity via a magic accessor
     * ($lead->{code}); we guard it so a missing/renamed attribute returns null
     * instead of throwing.
     *
     * TODO(odontoking): confirm the four attribute CODES against one real lead
     * (GET /api/v1/leads/{id}) and set them in config. Without them this returns null.
     */
    protected function leadAttribute($lead, string $key): ?string
    {
        if (! $lead) {
            return null;
        }

        $code = config("whatsapp.crm.attributes.{$key}");

        if (! $code) {
            return null;
        }

        try {
            $value = $lead->{$code} ?? null;

            if (is_array($value)) {
                // select/lookup attributes may resolve to ['id'=>, 'name'=>]
                $value = $value['name'] ?? $value['label'] ?? reset($value) ?: null;
            }

            $value = is_scalar($value) ? trim((string) $value) : null;

            return $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function empty(?int $personId = null, ?string $name = null): array
    {
        return [
            'person_id'              => $personId,
            'paciente_nuevo'         => true,
            'nombre_registrado'      => $name ?: null,
            'ci_paciente_registrada' => null,
            'seguro_registrado'      => null,
            'pacientes_a_cargo'      => [],
            'citas'                  => [],
            'cita_activa'            => null,
            'recordatorios'          => [],
        ];
    }

    /**
     * Pacientes vinculados a esta persona como responsable (person_relations).
     *
     * @return \Illuminate\Support\Collection<int, \Webkul\Contact\Models\Person>
     */
    protected function pacientesACargo(int $personId)
    {
        $relaciones = \Illuminate\Support\Facades\DB::table('person_relations')
            ->where('responsible_person_id', $personId)
            ->pluck('relation', 'patient_person_id');

        if ($relaciones->isEmpty()) {
            return collect();
        }

        return PersonProxy::modelClass()::whereIn('id', $relaciones->keys())
            ->orderBy('name')
            ->get()
            ->each(fn ($p) => $p->setAttribute('relation', $relaciones[$p->id] ?? null));
    }
}
