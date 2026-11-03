<?php

namespace Webkul\Whatsapp\Services;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Recordatorio a las 4 h de nuestro último mensaje y cierre cuando el cliente
 * no contesta: a las 24 h en "No atendido" (se le cerró la ventana) y a las
 * 72 h en el resto de las etapas.
 *
 * Todo se mide sobre el "último mensaje efectivo" de la conversación: el
 * último que no es plantilla y no falló. Las plantillas no cuentan porque
 * salen con la conversación ya fría (0 respuestas de 36 al medirlo), y un
 * mensaje que falló nunca le llegó al cliente.
 *
 * Si ese mensaje es nuestro (agente o asesor), el cliente nos debe una
 * respuesta. Si es del cliente, el pendiente es del equipo y no se hace nada.
 *
 * Spec: tasks/spec-sin-respuesta-3-dias.md.
 */
class SilenceTracker
{
    /** Título de la actividad que marca un cierre hecho por esta regla. */
    public const MARKER_TITLE = 'Cerrado automáticamente: sin respuesta del cliente en 3 días';

    /** Marca del cierre de "No atendido" con la ventana de 24 h cerrada. */
    public const UNATTENDED_MARKER_TITLE = 'Cerrado automáticamente: sin respuesta del cliente en 24 h (No atendido)';

    /** La ventana de servicio de WhatsApp: pasado esto solo salen plantillas. */
    public const WINDOW_HOURS = 24;

    public function __construct(protected ActivityRepository $activities) {}

    /**
     * Activa si hay fecha de activación. Antes de esa fecha nada cuenta.
     */
    public function since(): ?Carbon
    {
        $since = config('whatsapp.auto_close.since');

        return $since ? Carbon::parse($since) : null;
    }

    /**
     * Conversaciones a las que les toca el recordatorio: nuestro último
     * mensaje (agente o asesor) tiene 4 h y el cliente no contestó.
     *
     * Se cuenta desde nuestro mensaje y no desde el del cliente: si no, el
     * recordatorio podía salir minutos después de que un asesor escribiera.
     * Uno solo por silencio: el recordatorio también es mensaje nuestro, pero
     * no dispara otro (decidido el 28/10/2026).
     *
     * @return Collection<int, Conversation>
     */
    public function followupsDue(): Collection
    {
        $since = $this->since();

        if (! $since) {
            return collect();
        }

        $limit = now()->subHours((int) config('whatsapp.auto_close.followup_after'));

        return ConversationProxy::modelClass()::query()
            ->whereIn('lead_id', $this->leadsInStages())
            // Con la ventana cerrada el texto no sale. Sin este tope la
            // primera corrida le escribiría a conversaciones de hace meses.
            ->where('last_inbound_at', '>', now()->subHours(self::WINDOW_HOURS))
            // Derivada a un asesor: el seguimiento lo hace él. Con el agente
            // apagado sí se manda (decidido el 15/10/2026).
            ->where(fn ($q) => $q->whereNull('handoff_state')
                ->orWhereNotIn('handoff_state', [Conversation::HANDOFF_REQUESTED, Conversation::HANDOFF_ASSIGNED]))
            ->get()
            ->filter(function (Conversation $conv) use ($since, $limit) {
                $last = $this->lastEffectiveMessage($conv->id);

                if (! $last || $last->direction !== 'outbound') {
                    return false;
                }

                $at = Carbon::parse($last->created_at);

                return $at->gte($since)
                    && $at->lte($limit)
                    && ! $this->followupSentSince($conv->id, $conv->last_inbound_at);
            })
            ->values();
    }

    /**
     * Conversaciones cuyo pedido hay que cerrar: nuestro último mensaje tiene
     * 72 h o más y el cliente no contestó.
     *
     * @return Collection<int, Conversation>
     */
    public function closuresDue(): Collection
    {
        $since = $this->since();

        if (! $since) {
            return collect();
        }

        $limit = now()->subHours((int) config('whatsapp.auto_close.close_after'));

        return ConversationProxy::modelClass()::query()
            ->whereIn('lead_id', $this->leadsInStages())
            // Filtros baratos antes de mirar mensajes: si el cliente escribió
            // en las últimas 72 h no puede tocar cerrar, y si no hubo nada
            // desde la activación, tampoco.
            ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<=', $limit))
            ->where('last_message_at', '>=', $since)
            ->get()
            ->filter(function (Conversation $conv) use ($since, $limit) {
                $last = $this->lastEffectiveMessage($conv->id);

                if (! $last || $last->direction !== 'outbound') {
                    return false;
                }

                $at = Carbon::parse($last->created_at);

                return $at->gte($since) && $at->lte($limit);
            })
            // Un pedido con dos conversaciones se cierra una sola vez.
            ->unique('lead_id')
            ->values();
    }

    /**
     * Pedidos en "No atendido" con la ventana de 24 h cerrada y nuestro
     * mensaje como último: pasan a "sin interés" sin esperar los 3 días.
     *
     * No mira `since` a propósito: el 28/10/2026 se decidió mover también lo
     * acumulado. Si el último mensaje es del cliente no se toca: el
     * pendiente es del equipo, no falta de interés.
     *
     * @return Collection<int, Conversation>
     */
    public function unattendedClosuresDue(): Collection
    {
        if (! $this->since()) {
            return collect();
        }

        $windowStart = now()->subHours(self::WINDOW_HOURS);
        $conversations = ConversationProxy::modelClass();

        return $conversations::query()
            ->whereIn('lead_id', $this->leadsInStages([(string) config('whatsapp.auto_close.unattended_stage')]))
            ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<=', $windowStart))
            // Si el pedido tiene otra conversación con la ventana abierta,
            // todavía se puede hablar con el cliente.
            ->whereNotIn('lead_id', $conversations::query()
                ->select('lead_id')
                ->whereNotNull('lead_id')
                ->where('last_inbound_at', '>', $windowStart))
            ->get()
            ->filter(fn (Conversation $conv) => $this->lastEffectiveMessage($conv->id)?->direction === 'outbound')
            ->unique('lead_id')
            ->values();
    }

    /**
     * Manda el recordatorio por el mismo camino que los mensajes del agente.
     */
    public function sendFollowup(Conversation $conv): Model
    {
        $name = $this->firstName($conv);

        $message = MessageProxy::modelClass()::create([
            'conversation_id' => $conv->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'body'            => str_replace('{nombre}', $name ? ' '.$name : '', (string) config('whatsapp.auto_close.followup_text')),
            'status'          => 'queued',
            'sender'          => 'ia',
            // Marca para no repetirlo y para medir cuántos responden.
            'payload'         => ['followup' => true],
        ]);

        $conv->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return $message;
    }

    /**
     * Pasa el pedido a "Cliente sin interés" de SU pipeline y deja la marca.
     */
    public function close(Conversation $conv, string $title = self::MARKER_TITLE): bool
    {
        $lead = $conv->lead_id ? LeadProxy::modelClass()::find($conv->lead_id) : null;

        if (! $lead) {
            return false;
        }

        $stageId = $this->stageIn($lead->lead_pipeline_id, (string) config('whatsapp.auto_close.closed_stage'));

        if (! $stageId) {
            return false;
        }

        return $this->asSystemUser(function () use ($lead, $stageId, $title) {
            $this->moveTo($lead, $stageId);

            Event::dispatch('activity.create.before');

            $activity = $this->activities->create([
                'type'       => 'system',
                'title'      => $title,
                'is_done'    => 1,
                'user_id'    => (int) config('whatsapp.auto_close.user_id'),
                'additional' => json_encode(['auto_close' => true]),
            ]);

            $lead->activities()->attach($activity->id);

            Event::dispatch('activity.create.after', $activity);

            return true;
        });
    }

    /**
     * El cliente volvió a escribir: si el pedido lo cerró esta regla, se
     * reabre en "Cliente contactado". Si lo cerró un asesor, no se toca.
     *
     * Corre dentro de la ingesta del webhook: un error acá nunca puede costar
     * el mensaje del cliente, así que se registra y se sigue.
     */
    public function reopenIfAutoClosed(Conversation $conv): bool
    {
        try {
            $lead = $conv->lead_id ? LeadProxy::modelClass()::find($conv->lead_id) : null;

            if (! $lead || ! $this->closedByRule($lead)) {
                return false;
            }

            $stageId = $this->stageIn($lead->lead_pipeline_id, (string) config('whatsapp.auto_close.reopen_stage'));

            if (! $stageId) {
                return false;
            }

            return $this->asSystemUser(function () use ($lead, $stageId) {
                $this->moveTo($lead, $stageId);

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('WhatsApp: no se pudo reabrir el pedido cerrado por falta de respuesta', [
                'conversation_id' => $conv->id,
                'error'           => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ¿El pedido está en "sin interés" porque lo puso esta regla?
     *
     * La marca tiene que ser posterior al último cambio de etapa: si después
     * un asesor lo movió (aunque lo haya devuelto a "sin interés"), la
     * decisión ya es suya.
     */
    public function closedByRule(Model $lead): bool
    {
        if ($this->codeOf($lead->lead_pipeline_stage_id) !== config('whatsapp.auto_close.closed_stage')) {
            return false;
        }

        $marker = $lead->activities()
            ->whereIn('activities.title', [self::MARKER_TITLE, self::UNATTENDED_MARKER_TITLE])
            ->max('activities.id');

        if (! $marker) {
            return false;
        }

        $lastStageChange = $lead->activities()
            ->whereIn('activities.additional->attribute', $this->stageAttributeNames())
            ->max('activities.id');

        return $marker > (int) $lastStageChange;
    }

    /**
     * Mueve la etapa por el mismo camino que el kanban. La etapa es un
     * atributo del pedido: con un save() directo no queda en el historial, y
     * los listeners de lead.update.after (tablero, avisos) no se enteran.
     */
    protected function moveTo(Model $lead, int $stageId): void
    {
        Event::dispatch('lead.update.before', $lead->id);

        $updated = app(LeadRepository::class)->update([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $stageId,
        ], $lead->id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $updated);
    }

    /**
     * Último mensaje que no es plantilla ni falló.
     */
    public function lastEffectiveMessage(int $conversationId): ?object
    {
        return DB::table('whatsapp_messages')
            ->where('conversation_id', $conversationId)
            ->where('type', '!=', 'template')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'failed'))
            ->orderByDesc('id')
            ->first(['id', 'direction', 'created_at']);
    }

    protected function followupSentSince(int $conversationId, ?Carbon $from): bool
    {
        return DB::table('whatsapp_messages')
            ->where('conversation_id', $conversationId)
            ->where('payload->followup', true)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->exists();
    }

    /**
     * Ids de pedidos en las etapas donde aplica la regla, en cualquier pipeline.
     */
    protected function leadsInStages(?array $codes = null): Closure
    {
        $codes ??= config('whatsapp.auto_close.stages');

        $stageIds = DB::table('lead_pipeline_stages')
            ->get(['id', 'code'])
            // ltrim: la etapa 11 tiene code "-no-atendido".
            ->filter(fn ($s) => in_array(ltrim((string) $s->code, '-'), $codes, true))
            ->pluck('id')
            ->all();

        return fn ($q) => $q->select('id')->from('leads')->whereIn('lead_pipeline_stage_id', $stageIds);
    }

    protected function stageIn(?int $pipelineId, string $code): ?int
    {
        if (! $pipelineId) {
            return null;
        }

        return DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->get(['id', 'code'])
            ->first(fn ($s) => ltrim((string) $s->code, '-') === $code)
            ?->id;
    }

    protected function codeOf(?int $stageId): ?string
    {
        $code = $stageId ? DB::table('lead_pipeline_stages')->where('id', $stageId)->value('code') : null;

        return $code === null ? null : ltrim((string) $code, '-');
    }

    /**
     * Cómo aparece el cambio de etapa en el historial: LogsActivity guarda el
     * nombre legible del atributo ("Etapa"), o el code si no lo resuelve.
     */
    protected function stageAttributeNames(): array
    {
        return array_values(array_filter([
            'lead_pipeline_stage_id',
            DB::table('attributes')
                ->where('entity_type', 'leads')
                ->where('code', 'lead_pipeline_stage_id')
                ->value('name'),
        ]));
    }

    /**
     * El primer nombre para el saludo: el que dio el cliente (título del
     * pedido) o el de perfil. Sin letras ("." o sólo emojis) no hay saludo con
     * nombre. Siempre en formato título, nunca en mayúsculas.
     */
    protected function firstName(Conversation $conv): ?string
    {
        $title = $conv->lead_id ? (string) DB::table('leads')->where('id', $conv->lead_id)->value('title') : '';

        $candidates = [];

        if (str_contains($title, ' - ')) {
            $candidates[] = trim(substr($title, strrpos($title, ' - ') + 3));
        }

        $candidates[] = (string) $conv->wa_name;

        foreach ($candidates as $candidate) {
            $first = strtok(trim($candidate), ' ');

            if ($first === false || mb_strtolower($first) === 'cliente' || ! preg_match('/\p{L}/u', $first)) {
                continue;
            }

            return mb_convert_case(mb_strtolower($first), MB_CASE_TITLE, 'UTF-8');
        }

        return null;
    }

    /**
     * Corre como el usuario Sistema para que el historial diga quién fue, y
     * deja la sesión como estaba (la ingesta corre en la cola y puede tener
     * otra cosa cargada).
     */
    protected function asSystemUser(Closure $callback): mixed
    {
        $guard = auth()->guard('user');
        $previous = $guard->user();
        $system = UserProxy::modelClass()::find((int) config('whatsapp.auto_close.user_id'));

        if ($system) {
            $guard->setUser($system);
        }

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
