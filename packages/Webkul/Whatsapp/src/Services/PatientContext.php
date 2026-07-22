<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
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
        $labels        = (array) config('whatsapp.crm.stage_labels', []);
        $activaStage   = (int) config('whatsapp.crm.stage_activa', 7);

        try {
            // Every lead of the person is a candidate cita. Exclude the
            // conversación lead (Consultas). Oldest→newest for a readable history.
            $leads = $person->leads()
                ->orderBy('id')
                ->get()
                ->filter(fn ($lead) => (int) $lead->lead_pipeline_stage_id !== $consultaStage)
                ->values();
        } catch (Throwable $e) {
            // Never let a context-build failure drop the agent event — degrade to
            // "no history" and let the agent fall back to get_citas on demand.
            Log::warning('WhatsApp: could not build patient context', [
                'person_id' => $person->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->empty($person->id, $person->name);
        }

        $citas = $leads->map(function ($lead) use ($labels) {
            [$fecha, $hora] = $this->fechaHora($lead);

            return [
                'lead_id'  => $lead->id,
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
            'paciente_nuevo'         => $citas->isEmpty(),
            'nombre_registrado'      => $person->name ?: null,
            'ci_paciente_registrada' => $source ? $this->leadAttribute($source, 'ci') : null,
            'seguro_registrado'      => $source ? $this->leadAttribute($source, 'seguro') : null,
            'citas'                  => $citas->all(),
            'cita_activa'            => $activa?->id,
        ];
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
            'citas'                  => [],
            'cita_activa'            => null,
        ];
    }
}