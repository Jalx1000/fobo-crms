<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;

/**
 * Conversaciones derivadas a recepción (CONTRATO B).
 *
 * El agente pide el traspaso moviendo el lead-conversación a la etapa
 * Recepcionista. A partir de ahí el CRM deja de avisarle: mientras el lead esté
 * en esa etapa, atiende una persona.
 *
 * No hace falta ninguna señal de vuelta — recepción saca el lead de la etapa y
 * el agente se reanuda solo en el siguiente mensaje.
 */
class ReceptionStage
{
    /**
     * Los leads que están en recepción ahora mismo.
     *
     * Memoizado por request: aiEffective() corre una vez por fila de la lista de
     * conversaciones y esto no puede volverse una consulta por fila. El conjunto
     * es chico por naturaleza —solo las conversaciones derivadas— así que se
     * trae entero de una vez.
     *
     * @var array<int, int>|null
     */
    protected static ?array $leadIds = null;

    /**
     * @var array<int, int>|null
     */
    protected static ?array $personIds = null;

    /**
     * ¿Esta conversación está derivada a recepción?
     *
     * Se mira por lead Y por paciente a propósito. `conversations.lead_id`
     * apunta casi siempre al lead de la ÚLTIMA CITA, no al de la conversación
     * (medido: 287 a "Cita confirmadas" contra 16 a "Consultas"), y en 144 de
     * 486 conversaciones está en NULL. Exigir que coincida ese lead exacto haría
     * que la derivación no pausara nada en la mayoría de los casos, según cuál
     * lead mueva el agente.
     */
    public static function holds(?int $leadId, ?int $personId = null): bool
    {
        if ($leadId && isset(self::leadIds()[$leadId])) {
            return true;
        }

        return $personId ? isset(self::personIds()[$personId]) : false;
    }

    /**
     * Reactivar el agente: saca de Recepcionista a los leads de esta conversación
     * (por lead Y por paciente, igual que holds()) y los devuelve a la etapa que
     * tenían antes (leads.pre_reception_stage_id). Si no la conocemos, cae al
     * fallback configurado. Así aiEffective() vuelve a true y el agente se reanuda
     * en vez de que el toggle parpadee.
     */
    public static function resume(?int $leadId, ?int $personId): void
    {
        $stage = (int) config('whatsapp.crm.stage_recepcion');

        if (! $stage || (! $leadId && ! $personId)) {
            return;
        }

        $fallback = (int) config('whatsapp.crm.stage_reactivate_fallback', 1);

        $leads = DB::table('leads')
            ->where('lead_pipeline_stage_id', $stage)
            ->where(function ($q) use ($leadId, $personId) {
                if ($leadId) {
                    $q->orWhere('id', $leadId);
                }
                if ($personId) {
                    $q->orWhere('person_id', $personId);
                }
            })
            ->get(['id', 'pre_reception_stage_id']);

        foreach ($leads as $lead) {
            DB::table('leads')->where('id', $lead->id)->update([
                'lead_pipeline_stage_id' => $lead->pre_reception_stage_id ?: $fallback,
                'pre_reception_stage_id' => null,
                'updated_at'             => now(),
            ]);
        }

        // El memo por request quedó viejo tras mover los leads.
        self::flush();
    }

    /**
     * Pacientes con algún lead en recepción.
     *
     * @return array<int, int> person_id => person_id
     */
    public static function personIds(): array
    {
        if (self::$personIds !== null) {
            return self::$personIds;
        }

        $stage = (int) config('whatsapp.crm.stage_recepcion');

        if (! $stage) {
            return self::$personIds = [];
        }

        return self::$personIds = DB::table('leads')
            ->where('lead_pipeline_stage_id', $stage)
            ->whereNotNull('person_id')
            ->pluck('person_id', 'person_id')
            ->all();
    }

    /**
     * @return array<int, int> lead_id => lead_id
     */
    public static function leadIds(): array
    {
        if (self::$leadIds !== null) {
            return self::$leadIds;
        }

        $stage = (int) config('whatsapp.crm.stage_recepcion');

        if (! $stage) {
            return self::$leadIds = [];
        }

        return self::$leadIds = DB::table('leads')
            ->where('lead_pipeline_stage_id', $stage)
            ->pluck('id', 'id')
            ->all();
    }

    /**
     * Olvida el memo — para tests y para workers de vida larga, que si no nunca
     * verían una derivación hecha después de arrancar.
     */
    public static function flush(): void
    {
        self::$leadIds = null;
        self::$personIds = null;
    }
}
