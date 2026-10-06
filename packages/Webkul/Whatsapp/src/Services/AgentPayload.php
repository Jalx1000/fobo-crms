<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Services\CitySyncService;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Support\UbicacionDeMensaje;

/**
 * Builds the contract the CRM sends to the external AI agent, and the shared
 * conversation history both the event and the agent's GET endpoint expose.
 */
class AgentPayload
{
    public function __construct(protected GatewayManager $gateways) {}

    /**
     * The `message.received` event for an inbound message.
     */
    public function event(Message $message): array
    {
        $conversation = $message->conversation;
        $window = $this->window($conversation);

        return [
            'event'           => 'message.received',
            'conversation_id' => $conversation->id,
            'gateway'         => $conversation->gateway,
            'ai_enabled'      => $conversation->aiEffective(),
            'contact'         => $this->contact($conversation),
            'lead'            => $this->lead($conversation),
            'message'         => $this->message($message),
            'history'         => $this->history($conversation),
            'window'          => [
                'open'       => $window->open,
                'expires_at' => $window->expiresAt?->toIso8601String(),
            ],
            'reply' => [
                'method' => 'POST',
                'url'    => url("api/v1/whatsapp/conversations/{$conversation->id}/messages"),
            ],
        ];
    }

    /**
     * One inbound message as the agent sees it.
     *
     * `text` is always the human-readable content, including when the customer
     * answered by tapping a button or a list row — those arrive from Meta with
     * no text at all, and the agent used to drop the turn. `type` still reports
     * what really happened, and `interactive` carries the option's id for
     * agents that would rather branch on the payload than on the label.
     */
    protected function message(Message $message): array
    {
        $selection = $this->gateways->for($message->conversation)
            ->interactiveSelection((array) $message->payload);

        $ubicacion = UbicacionDeMensaje::de((array) $message->payload);

        /*
         * El string listo para copiar a `ubicacion_lead`, DENTRO de `location`.
         *
         * Va acá y no en `text` porque un link de Maps llega como mensaje de
         * texto: ahi `text` es lo que escribio el cliente ("aca vivo <link>") y
         * copiarlo guardaria la URL en el CRM. Con esto el agente tiene UNA
         * regla que vale para el pin y para el link: si hay `location`, guardar
         * `location.text`.
         */
        if ($ubicacion) {
            $ubicacion['text'] = UbicacionDeMensaje::texto((array) $message->payload);
        }

        $payload = [
            'id'        => $message->id,
            'type'      => $message->type,
            'text'      => $this->texto($message),
            /*
             * La opción que el cliente tocó. MISMA CLAVE Y MISMA FORMA que en
             * imprimir (`selection` con {id, title}), porque el agente es el
             * mismo para los dos tenants: cuando kohlberg mandaba esto bajo otra
             * clave, el agente leía `selection` y encontraba undefined — los
             * botones y las listas "no se detectaban".
             *
             * `text` ya trae el título, que es lo que el cliente ve, pero el
             * agente debe rutear por el `id`: los títulos se recortan a 20/24
             * caracteres y pueden repetirse entre menús; el id no.
             */
            'selection' => $selection ? [
                'id'    => (string) ($selection['id'] ?? ''),
                'title' => (string) ($selection['title'] ?? ''),
            ] : null,
            /*
             * El pin que el cliente compartio, ya separado en numeros.
             *
             * `text` trae lo mismo en el formato de `ubicacion_lead` para que el
             * agente pueda reenviarlo tal cual, pero rutear por numeros es mas
             * seguro que parsear un string.
             */
            'location'  => $ubicacion,
            'timestamp' => optional($message->created_at)->toIso8601String(),
        ];

        /*
         * Las MISMAS coordenadas tambien planas.
         *
         * El equipo del agente dijo que acepta las dos formas —`message.location`
         * anidado o `message.latitude`/`message.longitude` arriba— y se mandan
         * las dos a proposito: son cuatro bytes y no depende de que punta lea
         * cual. Esto fue EL bug: el evento salia con `type: location` y nada
         * mas, asi que el agente no tenia ubicacion para guardar y terminaba
         * pidiendole al cliente que escribiera la direccion a mano.
         */
        if ($ubicacion) {
            $payload['latitude'] = $ubicacion['lat'];
            $payload['longitude'] = $ubicacion['lng'];
        }

        // Se mantiene la clave vieja para no romper a quien ya la lea.
        if ($selection) {
            $payload['interactive'] = $selection;
        }

        return $payload;
    }

    public function contact(Conversation $conversation): array
    {
        return [
            'phone'     => $conversation->wa_phone,
            'name'      => $conversation->wa_name,
            'person_id' => $conversation->person_id,
            'lead_id'   => $conversation->lead_id,
        ];
    }

    /**
     * El pedido que el CRM ya abrió para este contacto.
     *
     * Existe para que el agente NO cree uno nuevo. Cuando alguien escribe por
     * primera vez, LeadOpener le abre el person y el lead; si el agente después
     * hace POST, quedan dos pedidos para la misma venta —pasó 81 veces—.
     *
     * El interruptor es `is_empty`, no `is_open`: un lead por pedido.
     *
     *   is_empty = true  => PUT sobre este id (es el que abrió el CRM, llenalo)
     *   is_empty = false => POST (pedido nuevo, aunque el anterior siga abierto)
     *
     * Se midió por qué: de 25 segundos pedidos sobre un lead abierto CON
     * productos, 15 eran pedidos genuinamente distintos —#648 y #1326 están a 33
     * días y con productos distintos— y sólo 10 eran correcciones. Decidir por
     * `is_open` los habría fusionado a los 15 en un pedido solo.
     *
     * `is_open` se mantiene porque sigue siendo cierto y útil para contexto,
     * pero no es lo que decide PUT vs POST.
     *
     * 🔴 `products` viene completo a propósito. El PUT REEMPLAZA la lista, no la
     * suma: lo que no se mande se borra. Importa cuando el agente corrige un
     * pedido ya registrado (PUT sobre un lead con contenido): manda esto más lo
     * nuevo y no pierde lo que ya estaba.
     *
     * @return array{id: int, is_empty: bool, is_open: bool, stage: ?string, lead_value: float, products: array}|null
     */
    public function lead(Conversation $conversation): ?array
    {
        if (! $conversation->lead_id) {
            return null;
        }

        $lead = LeadProxy::modelClass()::with(['products', 'stage'])->find($conversation->lead_id);

        if (! $lead) {
            return null;
        }

        return [
            'id'         => (int) $lead->id,
            'is_empty'   => $lead->products->isEmpty(),
            'is_open'    => ! in_array(optional($lead->stage)->code, CitySyncService::CLOSED_STAGE_CODES, true),
            'stage'      => optional($lead->stage)->name,
            'lead_value' => (float) $lead->lead_value,
            'products'   => $lead->products->map(fn ($p) => [
                'product_id' => (int) $p->product_id,
                'name'       => $p->name,
                'quantity'   => (float) $p->quantity,
                'price'      => (float) $p->price,
            ])->values()->all(),
        ];
    }

    /**
     * Recent messages as role/content pairs for the agent's context.
     *
     * @return array<int, array{role: string, content: ?string, type: string}>
     */
    public function history(Conversation $conversation): array
    {
        $limit = (int) config('whatsapp.ai.history_size', 15);

        return $conversation->messages()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->map(fn (Message $m) => [
                'role'    => $m->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $this->texto($m),
                'type'    => $m->type,
            ])
            ->values()
            ->all();
    }

    /**
     * El contenido legible de un mensaje.
     *
     * Es `body` salvo para las ubicaciones, que se derivan del `payload`. Se
     * derivan y no se leen de `body` a proposito: las ubicaciones que ya estan
     * guardadas tienen `body` null —el gateway no lo llenaba— y con esto el
     * agente las ve igual, sin migracion.
     */
    protected function texto(Message $message): ?string
    {
        if ($message->type === 'location') {
            return UbicacionDeMensaje::texto((array) $message->payload) ?? $message->body;
        }

        return $message->body;
    }

    protected function window(Conversation $conversation): MessagingWindow
    {
        return MessagingWindow::compute(
            $this->gateways->for($conversation)->messagingWindowHours(),
            $conversation->last_inbound_at,
        );
    }
}
