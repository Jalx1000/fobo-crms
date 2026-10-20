<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Core\Services\AlcanceCiudades;
use Webkul\Lead\Services\CitySyncService;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Support\ConversationCity;

/**
 * Preguntarle la ciudad al cliente que todavía no la dijo.
 *
 * Sin ciudad no hay distribuidor al que asignarle el cliente: el pedido queda sin
 * dueño y no lo ve ningún distribuidor. El agente suele preguntarla en la charla,
 * pero si no la consiguió, el CRM la pide con una lista de ciudades para tocar.
 *
 * 🔴 Tiene que salir ANTES de que se cierre la ventana de 24 h desde el último
 * mensaje del cliente: después, WhatsApp sólo deja escribirle con una plantilla
 * aprobada. Por eso la búsqueda mira sólo conversaciones con un mensaje entrante
 * dentro de la ventana.
 *
 * Cuando el cliente toca una ciudad, se guarda en su ficha y desde ahí el reparto
 * hace lo de siempre: sus pedidos se mudan a esa ciudad y le toca un distribuidor.
 */
class PreguntaDeCiudad
{
    /** El id de cada opción de la lista: "ciudad:<pipeline>". */
    public const PREFIJO = 'ciudad:';

    public function __construct(
        protected Reparto $reparto,
    ) {}

    /**
     * Las conversaciones a las que hay que preguntarles la ciudad ahora.
     *
     * - Último mensaje del cliente hace más de `tras_minutos` (se le da tiempo al
     *   agente a preguntarla él) y dentro de la ventana de 24 h.
     * - Sin ciudad por ningún camino (o con "Sin ciudad").
     * - No se le preguntó ya en esta ventana.
     *
     * @return Collection<int, int>
     */
    public function pendientes(int $limite = 50): Collection
    {
        $config = (array) config('distribuidor.pregunta_ciudad');
        $sql = ConversationCity::sql();
        $sinCiudad = ConversationCity::noneEquivalentPipelineIds();

        return DB::table('whatsapp_conversations')
            ->where('gateway', config('whatsapp.gateway'))
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', now()->subMinutes((int) ($config['tras_minutos'] ?? 60)))
            ->where('last_inbound_at', '>=', now()->subHours((int) ($config['ventana_horas'] ?? 23)))
            ->where(fn ($q) => $q->whereNull('ciudad_preguntada_at')
                ->orWhere('ciudad_preguntada_at', '<', now()->subDay()))
            ->where(function ($q) use ($sql, $sinCiudad) {
                $q->whereRaw($sql.' is null');

                if ($sinCiudad) {
                    $q->orWhereRaw($sql.' in ('.implode(',', array_fill(0, count($sinCiudad), '?')).')', $sinCiudad);
                }
            })
            ->orderBy('last_inbound_at')
            ->limit($limite)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Las ciudades que se ofrecen: las que tienen al menos un distribuidor activo.
     * Ofrecer una ciudad sin distribuidor sería pedir un dato que no lleva a nadie.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public function opciones(): array
    {
        return DB::table('distribuidor_zonas')
            ->join('users', 'users.id', '=', 'distribuidor_zonas.user_id')
            ->join('lead_pipelines', 'lead_pipelines.id', '=', 'distribuidor_zonas.lead_pipeline_id')
            ->where('users.status', 1)
            ->whereNotIn('lead_pipelines.id', ConversationCity::noneEquivalentPipelineIds() ?: [0])
            ->select('lead_pipelines.id', 'lead_pipelines.name')
            ->distinct()
            ->orderBy('lead_pipelines.name')
            ->limit(OutboundInteractive::MAX_ROWS)
            ->get()
            ->map(fn ($c) => [
                'id'    => self::PREFIJO.$c->id,
                'title' => mb_substr((string) $c->name, 0, OutboundInteractive::MAX_ROW_TITLE),
            ])
            ->all();
    }

    /**
     * Le manda la lista de ciudades. Devuelve si quedó encolada.
     */
    public function preguntar(int $conversationId): bool
    {
        $opciones = $this->opciones();

        if (! $opciones) {
            return false;
        }

        $config = (array) config('distribuidor.pregunta_ciudad');

        $lista = OutboundInteractive::list(
            (string) ($config['texto'] ?? '¿De qué ciudad nos escribís? Así te asignamos al distribuidor de tu zona.'),
            $opciones,
            (string) ($config['boton'] ?? 'Elegir ciudad'),
        );

        $message = DB::transaction(function () use ($conversationId, $lista) {
            $conversation = ConversationProxy::modelClass()::lockForUpdate()->find($conversationId);

            // Otra corrida ya la preguntó mientras tanto: no se pregunta dos veces.
            if (! $conversation || ($conversation->ciudad_preguntada_at && $conversation->ciudad_preguntada_at->gt(now()->subDay()))) {
                return null;
            }

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'interactive',
                'body'            => $lista->label(),
                'status'          => 'queued',
                'sender'          => 'ia',
                'payload'         => ['interactive' => $lista->toArray(), 'motivo' => 'pregunta_ciudad'],
            ]);

            $conversation->forceFill([
                'ciudad_preguntada_at' => now(),
                'last_message_at'      => now(),
            ])->save();

            return $message;
        });

        if (! $message) {
            return false;
        }

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return true;
    }

    /**
     * Si el mensaje entrante responde la pregunta, guarda la ciudad y asigna.
     *
     * Acepta tocar la opción de la lista y también escribir el nombre de la ciudad
     * ("palpala", "Alto Comedero"): mucha gente contesta escribiendo en vez de tocar.
     *
     * @return int|null La ciudad registrada.
     */
    public function registrarRespuesta($message): ?int
    {
        if ($message->direction !== 'inbound') {
            return null;
        }

        $conversation = ConversationProxy::modelClass()::find($message->conversation_id);

        if (! $conversation?->ciudad_preguntada_at || ! $conversation->person_id) {
            return null;
        }

        $ciudad = $this->deLaSeleccion($message) ?? $this->delTexto((string) $message->body);

        if (! $ciudad) {
            return null;
        }

        $personId = (int) $conversation->person_id;

        $this->guardarEnLaFicha($personId, $ciudad);

        // Sus pedidos se mudan a la ciudad (y el reparto les pone distribuidor)...
        if ($person = PersonProxy::modelClass()::find($personId)) {
            app(CitySyncService::class)->syncFromPerson($person);
        }

        // ...y aunque todavía no tenga pedidos, el cliente ya queda con su dueño.
        $this->reparto->duenoPara($personId, $ciudad);

        Log::info('Ciudad registrada por respuesta del cliente', [
            'conversation_id' => $conversation->id,
            'person_id'       => $personId,
            'ciudad'          => $ciudad,
        ]);

        return $ciudad;
    }

    protected function deLaSeleccion($message): ?int
    {
        $seleccion = app(GatewayManager::class)->active()->interactiveSelection((array) $message->payload);
        $id = (string) ($seleccion['id'] ?? '');

        if (! str_starts_with($id, self::PREFIJO)) {
            return null;
        }

        $ciudad = (int) substr($id, strlen(self::PREFIJO));

        return in_array($ciudad, $this->ciudadesOfrecidas(), true) ? $ciudad : null;
    }

    /**
     * El nombre de una ciudad escrito a mano. Sólo si nombra a UNA: "jujuy" puede ser
     * San Salvador de Jujuy o la provincia, y adivinar mal manda el cliente a otra
     * empresa.
     */
    protected function delTexto(string $texto): ?int
    {
        $escrito = AlcanceCiudades::normalizar($texto);

        if ($escrito === '') {
            return null;
        }

        $coinciden = DB::table('lead_pipelines')
            ->whereIn('id', $this->ciudadesOfrecidas())
            ->get(['id', 'name'])
            ->filter(function ($c) use ($escrito) {
                $nombre = AlcanceCiudades::normalizar((string) $c->name);

                return str_contains($escrito, $nombre)
                    || (mb_strlen($escrito) >= 5 && str_contains($nombre, $escrito));
            });

        return $coinciden->count() === 1 ? (int) $coinciden->first()->id : null;
    }

    /** @return array<int, int> */
    protected function ciudadesOfrecidas(): array
    {
        return array_map(
            fn ($o) => (int) substr($o['id'], strlen(self::PREFIJO)),
            $this->opciones(),
        );
    }

    /**
     * Guarda la ciudad en la ficha. Pisa sólo un valor vacío o "Sin ciudad": una ciudad
     * real cargada por alguien del equipo no la cambia la respuesta a una pregunta
     * automática.
     */
    protected function guardarEnLaFicha(int $personId, int $ciudad): void
    {
        $atributo = DB::table('attributes')
            ->where('code', CitySyncService::PERSON_CITY_ATTRIBUTE_CODE)
            ->where('entity_type', 'persons')
            ->value('id');

        if (! $atributo) {
            return;
        }

        $actual = DB::table('attribute_values')
            ->where('entity_type', 'persons')
            ->where('entity_id', $personId)
            ->where('attribute_id', $atributo)
            ->first(['id', 'integer_value']);

        if (! $actual) {
            // Sin timestamps: attribute_values no los tiene en este esquema.
            DB::table('attribute_values')->insert([
                'entity_id'     => $personId,
                'entity_type'   => 'persons',
                'attribute_id'  => $atributo,
                'integer_value' => $ciudad,
            ]);

            return;
        }

        $reemplazable = $actual->integer_value === null
            || in_array((int) $actual->integer_value, ConversationCity::noneEquivalentPipelineIds(), true);

        if ($reemplazable) {
            DB::table('attribute_values')->where('id', $actual->id)->update(['integer_value' => $ciudad]);
        }
    }
}
