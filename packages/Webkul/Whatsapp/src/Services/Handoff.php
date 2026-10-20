<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Core\Services\Distribuidores;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Exceptions\HandoffConflictException;
use Webkul\Whatsapp\Jobs\NotifyHandoff;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Pasa una conversación del agente IA a una persona.
 *
 * Apagar `ai_enabled` ES el handoff, mecánicamente: IngestInboundPayload solo
 * notifica al agente mientras está encendido, y el composer se desbloquea al
 * apagarse. Todo lo demás —estado, motivo, asignado, auditoría— existe para que
 * el equipo sepa QUIÉN le debe una respuesta al cliente.
 *
 * El ruteo es por CIUDAD (ver CityRouter): en kohlberg cada ciudad es un
 * pipeline con un Encargado que la atiende.
 *
 * Este servicio nunca lee auth(). El actor siempre se pasa: la ruta del agente
 * corre bajo `auth:sanctum` (el token es de un usuario real, pero el ACTOR es
 * el bot), mientras que las rutas del admin corren bajo el guard `user`.
 * Deducirlo acá le daría a un humano el crédito de lo que hizo el agente.
 */
class Handoff
{
    public function __construct(
        protected ActivityRepository $activities,
        protected CityRouter $cities,
    ) {}

    /**
     * Abre una derivación.
     *
     * Idempotente: llamarla de nuevo con una abierta no re-rutea ni vuelve a
     * notificar (un LLM tiende a pedir el handoff en cada mensaje). Igual
     * fuerza el agente apagado, por si alguien lo volvió a encender.
     *
     * @param  string  $source  'ai' | 'human'
     * @param  int|null  $actorUserId  Quién lo pidió; null = el agente IA.
     * @param  string|int|null  $ciudad  Ciudad que el agente averiguó hablando.
     * @return array{conversation: Conversation, changed: bool}
     */
    public function request(
        Conversation $conversation,
        ?string $reason = null,
        string $source = 'ai',
        ?int $actorUserId = null,
        string|int|null $ciudad = null,
    ): array {
        $source = in_array($source, Conversation::HANDOFF_SOURCES, true) ? $source : 'ai';

        $result = DB::transaction(function () use ($conversation, $reason, $source, $ciudad) {
            /** @var Conversation $fresh */
            $fresh = $conversation->newQuery()->whereKey($conversation->id)->lockForUpdate()->first();

            if ($fresh->handoffOpen()) {
                // Ya es problema de alguien. Solo se reafirma el agente apagado.
                if ($fresh->ai_enabled !== false) {
                    $fresh->forceFill(['ai_enabled' => false])->save();
                }

                return ['conversation' => $fresh, 'changed' => false, 'owner' => $fresh->assigned_user_id];
            }

            $cityId = $this->cities->resolve($fresh, $ciudad);

            /*
             * Si el cliente ya es de un distribuidor, la derivación va a ESE: es su
             * cliente, y el asesor de la ciudad puede ser de otra empresa. Sin dueño
             * (cliente nuevo, o sin ciudad todavía), el ruteo de siempre por ciudad.
             */
            $owner = app(Distribuidores::class)->duenoDe($fresh->person_id ? (int) $fresh->person_id : null)
                ?? $this->cities->advisorFor($cityId);

            $fresh->forceFill([
                'ai_enabled'           => false,
                'handoff_state'        => $owner ? Conversation::HANDOFF_ASSIGNED : Conversation::HANDOFF_REQUESTED,
                // La columna aguanta 500; un LLM escribe más sin pestañear.
                'handoff_reason'       => $reason ? Str::limit($reason, 490, '') : null,
                'handoff_source'       => $source,
                'handoff_requested_at' => now(),
                'handoff_city_id'      => $cityId,
                'assigned_user_id'     => $owner,
                'assigned_at'          => $owner ? now() : null,
            ])->save();

            // Si la ciudad la aportó el agente y el cliente no la tenía, se
            // guarda: si no, el dato se usa una vez y se pierde.
            if ($cityId && $fresh->person_id) {
                $this->cities->rememberOnPerson($fresh->person_id, $cityId);
            }

            return ['conversation' => $fresh, 'changed' => true, 'owner' => $owner];
        });

        if ($result['changed']) {
            $ciudadNombre = $this->cities->cityName($result['conversation']->handoff_city_id);

            $this->audit(
                $result['conversation'],
                ($source === 'ai'
                    ? 'Derivación solicitada por el agente IA.'
                    : 'Derivación solicitada por un asesor.')
                    .($ciudadNombre ? ' Ciudad: '.$ciudadNombre.'.' : ' Sin ciudad: queda en el pool.')
                    .($reason ? ' Motivo: '.$reason : ''),
                $actorUserId,
            );

            // Fuera de la transacción a propósito: con cola Redis un worker
            // puede tomar el job antes del commit y leer estado viejo.
            $this->notify($result['conversation'], $result['owner']);
        }

        return ['conversation' => $result['conversation'], 'changed' => $result['changed']];
    }

    /**
     * Tomar una conversación del pool.
     *
     * Un solo UPDATE condicional, no leer-y-escribir: InnoDB serializa los
     * updates sobre la misma primary key, así que con dos asesores clickeando a
     * la vez exactamente uno obtiene $afectadas === 1 y el otro el conflicto.
     *
     * @throws HandoffConflictException
     */
    public function claim(Conversation $conversation, int $userId): Conversation
    {
        $afectadas = DB::table($conversation->getTable())
            ->where('id', $conversation->id)
            ->where('handoff_state', Conversation::HANDOFF_REQUESTED)
            ->whereNull('assigned_user_id')
            ->update([
                'handoff_state'    => Conversation::HANDOFF_ASSIGNED,
                'assigned_user_id' => $userId,
                'assigned_at'      => now(),
                'ai_enabled'       => false,
                // DB::table() esquiva Eloquent, así que el timestamp es nuestro.
                'updated_at'       => now(),
            ]);

        if ($afectadas === 0) {
            throw HandoffConflictException::alreadyClaimed();
        }

        $conversation->refresh();

        $this->audit($conversation, 'Derivación tomada por '.$this->userName($userId).'.', $userId);

        return $conversation;
    }

    /**
     * Pasarla a otro asesor a mano.
     *
     * @throws HandoffConflictException
     */
    public function reassign(Conversation $conversation, int $userId, ?int $actorUserId = null): Conversation
    {
        if (! $conversation->handoffOpen()) {
            throw HandoffConflictException::notOpen();
        }

        $anterior = $conversation->assigned_user_id;

        $conversation->forceFill([
            'handoff_state'    => Conversation::HANDOFF_ASSIGNED,
            'assigned_user_id' => $userId,
            'assigned_at'      => now(),
            'ai_enabled'       => false,
        ])->save();

        $this->audit($conversation, 'Derivación reasignada a '.$this->userName($userId).'.', $actorUserId);

        if ($anterior !== $userId) {
            $this->notify($conversation, $userId);
        }

        return $conversation;
    }

    /**
     * Cerrar la derivación.
     *
     * `$reactivateAi` por defecto true para que una conversación cerrada no
     * quede en tierra de nadie: agente apagado, sin derivación abierta, y nadie
     * enterado de que alguien debería contestar.
     *
     * `assigned_user_id` se conserva a propósito: es el registro de quién la
     * atendió.
     *
     * @throws HandoffConflictException
     */
    public function resolve(Conversation $conversation, ?int $actorUserId = null, bool $reactivateAi = true): Conversation
    {
        if (! $conversation->handoffOpen()) {
            throw HandoffConflictException::notOpen();
        }

        $conversation->forceFill([
            'handoff_state' => Conversation::HANDOFF_RESOLVED,
            'ai_enabled'    => $reactivateAi ? true : $conversation->ai_enabled,
        ])->save();

        $this->audit(
            $conversation,
            'Derivación cerrada'.($actorUserId ? ' por '.$this->userName($actorUserId) : '').'.'
                .($reactivateAi ? ' El agente IA vuelve a atender.' : ' El agente IA queda desactivado.'),
            $actorUserId,
        );

        return $conversation;
    }

    /**
     * La forma del handoff para el inbox.
     *
     * Todas las claves están siempre, nulls incluidos: el front asigna este
     * objeto entero en cada poll, y una clave faltante dejaría en pantalla el
     * valor de la conversación anterior.
     */
    public function toArray(?Conversation $conversation, ?int $viewerId = null, ?bool $changed = null): array
    {
        if (! $conversation) {
            return [
                'state'         => null, 'open' => false, 'pooled' => false, 'reason' => null,
                'source'        => null, 'requested_at' => null, 'city' => null,
                'assigned_user' => null, 'assigned_at' => null, 'is_mine' => false,
            ];
        }

        $conversation->loadMissing('assignedUser:id,name');

        $payload = [
            'state'         => $conversation->handoff_state,
            'open'          => $conversation->handoffOpen(),
            'pooled'        => $conversation->handoffPooled(),
            'reason'        => $conversation->handoff_reason,
            'source'        => $conversation->handoff_source,
            'requested_at'  => optional($conversation->handoff_requested_at)->toIso8601String(),
            'city'          => $this->cities->cityName($conversation->handoff_city_id),
            'assigned_user' => $conversation->assignedUser
                ? ['id' => $conversation->assignedUser->id, 'name' => $conversation->assignedUser->name]
                : null,
            'assigned_at'   => optional($conversation->assigned_at)->toIso8601String(),
            'is_mine'       => $viewerId !== null
                && $conversation->assigned_user_id !== null
                && (int) $conversation->assigned_user_id === (int) $viewerId,
        ];

        if ($changed !== null) {
            $payload['changed'] = $changed;
        }

        return $payload;
    }

    /**
     * El mismo bloque, con la forma que espera el agente.
     *
     * El inbox necesita el nombre del asesor, así que toArray() modela
     * `assigned_user` como objeto. El esquema del agente tipa esa clave como
     * entero, así que apenas alguien tomaba una conversación el agente
     * rechazaba el evento entero (ValidationError de pydantic) y dejaba de
     * responder — mientras seguía contestando 200, que es por lo que del lado
     * del CRM nunca falló nada visible.
     *
     * El id mantiene la clave acordada y el nombre viaja al lado, así que el
     * agente no pierde nada. Pasó en el tenant de impresión; no repetirlo.
     */
    public function toAgentArray(?Conversation $conversation, ?bool $changed = null): array
    {
        $payload = $this->toArray($conversation, null, $changed);

        $user = $payload['assigned_user'] ?? null;

        $payload['assigned_user'] = is_array($user) ? ($user['id'] ?? null) : $user;
        $payload['assigned_user_name'] = is_array($user) ? ($user['name'] ?? null) : null;

        return $payload;
    }

    protected function notify(Conversation $conversation, ?int $userId): void
    {
        if (! config('whatsapp.handoff.notify', true)) {
            return;
        }

        NotifyHandoff::dispatch($conversation->id, $userId)->onQueue(config('whatsapp.queue'));
    }

    /**
     * Deja una actividad `note` de Krayin en el contacto/lead, igual que
     * InboxController::storeNote(), para que la derivación aparezca en el
     * timeline y dentro del hilo de WhatsApp sin trabajo extra.
     *
     * A diferencia de storeNote(), una conversación sin persona ni lead NO es
     * un error acá: un número desconocido es un caso normal. Y que falle la
     * auditoría nunca debe abortar el handoff — los datos duros ya viven en las
     * columnas de la conversación.
     */
    protected function audit(Conversation $conversation, string $comment, ?int $actorUserId): void
    {
        if (! $conversation->person_id && ! $conversation->lead_id) {
            Log::info('WhatsApp: handoff sin persona ni lead, no se registra actividad', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        try {
            Event::dispatch('activity.create.before');

            $activity = $this->activities->create([
                'type'    => 'note',
                'comment' => $comment,
                'is_done' => 1,
                'user_id' => $actorUserId,
            ]);

            if ($conversation->person_id && $conversation->person) {
                $conversation->person->activities()->attach($activity->id);
            }

            if ($conversation->lead_id && $conversation->lead) {
                $conversation->lead->activities()->attach($activity->id);
            }

            Event::dispatch('activity.create.after', $activity);
        } catch (Throwable $e) {
            Log::warning('WhatsApp: no se pudo registrar la actividad del handoff', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    protected function userName(?int $userId): string
    {
        if (! $userId) {
            return 'el sistema';
        }

        return UserProxy::modelClass()::where('id', $userId)->value('name') ?: 'un asesor';
    }
}
