<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
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

        /*
         * `responder` es lo que el agente tiene que mirar antes de contestar: solo
         * un mensaje DEL CLIENTE con el agente prendido es un turno suyo. El resto
         * (agente apagado, o lo que escribió el asesor) le llega para que sepa de
         * qué se habló, no para que responda.
         */
        $entrante = $message->direction === 'inbound';

        return [
            'event'           => $entrante ? 'message.received' : 'message.sent',
            'responder'       => $entrante && $conversation->aiEffective(),
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
            // Lo que YA sabemos de este cliente, para no volver a preguntárselo.
            'cliente'            => $this->cliente($conversation),
            'pedidos_pendientes' => $this->pedidosPendientes($conversation),
            // A quién se le asigna un pedido según la ciudad.
            'asignacion' => $this->asignacion(),
            // Productos y precios vigentes de cada zona: el agente no depende de acordarse
            // de pedirlos (en la conversación 7710 contestó «no hay productos» sin consultar).
            'catalogo' => $this->catalogo($conversation),
            // Si venimos de un recordatorio de recompra: qué le preguntamos, qué sabemos
            // ya de él y dónde avisar qué contestó.
            'recompra' => $this->recompra($conversation),
            'reply'    => [
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

    /**
     * El contexto del recordatorio de recompra.
     *
     * Devuelve null cuando no hay ninguno pendiente, que es el caso normal: una
     * conversación cualquiera no tiene nada que ver con esto y el bloque sobraría.
     *
     * 🔴 `datos_completos` es lo que resuelve el pedido del negocio: "que el agente
     * tome el pedido sin volver a preguntar la ubicación, porque ya la tenemos". El
     * agente no puede deducirlo —desde su lado, todas las conversaciones se parecen—
     * así que se lo decimos, y le mandamos el domicilio para que lo CONFIRME en vez
     * de pedirlo de nuevo.
     *
     * @return array{esperando_respuesta:bool, intento:int, ultima_entrega:?string, datos_completos:bool, domicilio:?array, responder:array}|null
     */
    public function recompra(Conversation $conversation): ?array
    {
        if (! $conversation->person_id || ! class_exists(\Webkul\Distribuidor\Services\Recordatorios::class)) {
            return null;
        }

        $estado = app(\Webkul\Distribuidor\Services\Recordatorios::class)->de((int) $conversation->person_id);

        if (! $estado || ! $estado->ultimo_envio_at) {
            return null;
        }

        /*
         * Sólo mientras la pregunta esté viva. Pasada la ventana de reintento, lo que
         * el cliente escriba ya no es "la respuesta al recordatorio" sino una
         * conversación nueva, y marcarla como respuesta haría que el agente conteste
         * sobre algo que el cliente no preguntó.
         */
        $dias = (int) (config('distribuidor.recordatorios.reintento_dias') ?? 2);

        if ($estado->ultimo_envio_at->copy()->addDays($dias)->isPast()) {
            return null;
        }

        $domicilio = app(\Webkul\Distribuidor\Services\Ubicacion::class)
            ->deCliente((int) $conversation->person_id);

        return [
            'esperando_respuesta' => $estado->ultima_respuesta === null,
            'intento'             => (int) $estado->intentos,
            'ultima_entrega'      => $estado->ultimo_envio_at->toIso8601String(),
            // Con domicilio guardado no hay nada que preguntar: se confirma y se toma
            // el pedido. Sin domicilio sí hay que pedirlo, aunque sea un cliente viejo.
            'datos_completos'    => (bool) $domicilio,
            'domicilio'          => $domicilio,
            /*
             * Dónde avisar qué contestó. Va en el payload y no en la documentación
             * para que el agente no tenga que construir la URL: las tres respuestas
             * posibles y el endpoint, juntos.
             */
            'responder' => [
                'method'     => 'POST',
                'url'        => url("api/v1/whatsapp/conversations/{$conversation->id}/recompra"),
                'respuestas' => [
                    'si'   => 'Quiere pedir ahora. Tomá el pedido sin volver a pedirle el domicilio.',
                    'no'   => 'No quiere ahora. Se le vuelve a preguntar en unos días.',
                    'baja' => 'No quiere recibir más estos recordatorios. No se le manda ninguno más.',
                ],
            ],
        ];
    }

    /**
     * Lo que ya sabemos de este cliente.
     *
     * 🔴 Es lo que resuelve "el agente vuelve a preguntar todo el flujo". El agente no
     * se olvidaba de los datos: NUNCA LOS RECIBÍA. El evento le mandaba teléfono,
     * nombre y dos ids, y nada más — así que la única conducta posible era volver a
     * preguntar.
     *
     * Devuelve null si la conversación todavía no está ligada a una persona: ahí no hay
     * nada que saber y el bloque sobraría.
     *
     * @return array{es_conocido:bool, ciudad:?string, distribuidor:?string, domicilio:?array, datos_completos:bool, bidones_en_su_casa:int, ultimo_pedido:?array}|null
     */
    public function cliente(Conversation $conversation): ?array
    {
        $personId = $conversation->person_id ? (int) $conversation->person_id : null;

        if (! $personId) {
            return null;
        }

        $ultimo = LeadProxy::modelClass()::with(['products', 'stage'])
            ->where('person_id', $personId)
            ->orderByDesc('id')
            ->first();

        $domicilio = $this->siExiste(
            \Webkul\Distribuidor\Services\Ubicacion::class,
            fn ($s) => $s->deCliente($personId),
        );

        $ciudadId = $ultimo?->lead_pipeline_id ? (int) $ultimo->lead_pipeline_id : null;

        return [
            /*
             * "Conocido" es haber comprado antes, no estar en la agenda. Alguien a quien
             * se le abrió la ficha porque escribió una vez no tiene datos que reusar, y
             * tratarlo como conocido haría que el agente dé por confirmado un domicilio
             * que nadie cargó.
             */
            'es_conocido'  => (bool) $ultimo,
            'ciudad'       => $ciudadId ? $this->nombreDeCiudad($ciudadId) : null,
            /*
             * El distribuidor DUEÑO del cliente, no "el de la ciudad": con varias empresas
             * en la misma ciudad, el primero de la lista puede ser otro.
             */
            'distribuidor' => app(\Webkul\Core\Services\Distribuidores::class)->nombre(
                app(\Webkul\Core\Services\Distribuidores::class)->duenoDe($personId)
            ) ?? ($ciudadId ? $this->distribuidorDe($ciudadId) : null),
            'domicilio'    => $domicilio,
            // Con domicilio guardado no hay nada que preguntar: se confirma y se toma el
            // pedido. Sin domicilio sí hay que pedirlo, aunque sea un cliente viejo.
            'datos_completos'    => (bool) $domicilio,
            'bidones_en_su_casa' => (int) $this->siExiste(
                \Webkul\Distribuidor\Services\Bidones::class,
                fn ($s) => $s->saldoDe($personId),
            ),
            'ultimo_pedido' => $ultimo ? [
                'id'        => (int) $ultimo->id,
                'fecha'     => $ultimo->created_at?->toDateString(),
                'etapa'     => optional($ultimo->stage)->name,
                'productos' => $this->lineasDe($ultimo),
            ] : null,
        ];
    }

    /**
     * Los pedidos de esta persona que todavía no se entregaron.
     *
     * Existe para que el agente no abra un duplicado. Hoy sólo ve el pedido de ESTA
     * conversación (`lead`), así que a un cliente que ya tenía un pedido abierto le
     * creaba otro sin enterarse.
     *
     * 🔴 "Pendiente" se decide por la etapa, y las etapas cerradas son las de
     * `CLOSED_STAGE_CODES` — que hasta esta versión no coincidían con ninguna etapa
     * real, así que un pedido entregado habría entrado acá como pendiente.
     *
     * @return array<int, array{id:int, etapa:?string, fecha:?string, total:float, productos:array}>
     */
    public function pedidosPendientes(Conversation $conversation): array
    {
        $personId = $conversation->person_id ? (int) $conversation->person_id : null;

        if (! $personId) {
            return [];
        }

        return LeadProxy::modelClass()::with(['products', 'stage'])
            ->where('person_id', $personId)
            ->whereHas('stage', fn ($q) => $q->whereNotIn('code', CitySyncService::CLOSED_STAGE_CODES))
            ->orderByDesc('id')
            // Un tope por si una ficha acumuló pedidos viejos sin cerrar: lo que el
            // agente necesita decidir es si suma a uno reciente, no leer el historial.
            ->limit(10)
            ->get()
            ->map(fn ($lead) => [
                'id'        => (int) $lead->id,
                'etapa'     => optional($lead->stage)->name,
                'fecha'     => $lead->created_at?->toDateString(),
                'total'     => (float) $lead->lead_value,
                'productos' => $this->lineasDe($lead),
            ])
            ->values()
            ->all();
    }

    /**
     * Qué distribuidor reparte en cada ciudad.
     *
     * Va en el payload y no en el prompt del agente a propósito: si los ids viven en el
     * prompt, el día que entra un distribuidor nuevo hay que editar el prompt, y hasta
     * que alguien lo haga el agente sigue mandando el id del anterior. Acá sale de la
     * base, que es donde se mantiene.
     *
     * @return array{nota:string, zonas:array}
     */
    public function asignacion(): array
    {
        return [
            /*
             * El agente ya no elige distribuidor. Con varias empresas por ciudad, el CRM
             * asigna: el cliente vuelve a su distribuidor, y uno nuevo va por turno. Un
             * user_id que mande el agente se ignora.
             */
            'nota' => 'NO mandes user_id ni elijas distribuidor: el CRM lo asigna solo '
                .'(el cliente vuelve a su distribuidor; uno nuevo se reparte por turno). '
                .'Lo único que necesita es la ciudad del pedido.',
            'zonas' => $this->siExiste(
                \Webkul\Distribuidor\Services\AsignacionDeZona::class,
                fn ($s) => $s->zonas(),
            ) ?: [],
        ];
    }

    /**
     * Lo que se vende en cada zona con cobertura, con su precio, y la zona del
     * cliente si se sabe.
     *
     * Van TODAS las zonas y no solo la del cliente: muchas veces el cliente dice
     * su zona en el mismo mensaje y el CRM todavía no la tiene guardada. Solo
     * productos activos (ProductosActivos, vía Catalogo).
     *
     * @return array{zonas_con_cobertura: array<int, string>, zona_del_cliente: ?string, por_zona: array<string, array>, nota: string}|null
     */
    public function catalogo(Conversation $conversation): ?array
    {
        $catalogo = $this->siExiste(\Webkul\Distribuidor\Services\Catalogo::class, fn ($c) => $c);

        if (! $catalogo) {
            return null;
        }

        $porZona = [];

        foreach ($catalogo->sucursales() as $sucursal) {
            if (! $sucursal['ciudad_id'] || ! ($zona = $this->nombreDeCiudad($sucursal['ciudad_id']))) {
                continue;
            }

            $productos = $catalogo->productosDe($sucursal['id']);

            if ($productos) {
                $porZona[$zona] = $productos;
            }
        }

        $ids = collect($porZona)->flatten(1)->pluck('id')->unique()->all();

        $descripciones = DB::table('products')->whereIn('id', $ids)->pluck('description', 'id');

        $promociones = DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->where('attributes.code', 'precio_promocion')
            ->where('attribute_values.entity_type', 'products')
            ->whereIn('attribute_values.entity_id', $ids)
            ->pluck('attribute_values.text_value', 'attribute_values.entity_id');

        $porZona = array_map(fn ($productos) => array_map(fn ($p) => [
            'product_id'       => $p['id'],
            'nombre'           => $p['name'],
            'precio'           => $p['price'],
            'precio_promocion' => filled($promociones[$p['id']] ?? null) ? $promociones[$p['id']] : null,
            'descripcion'      => $descripciones[$p['id']] ?? null,
        ], $productos), $porZona);

        $ciudadId = \Webkul\Whatsapp\Support\ConversationCity::de((int) $conversation->id);

        // «Sin ciudad» es un pipeline pero no una zona: para el agente, no se sabe.
        if (in_array($ciudadId, \Webkul\Whatsapp\Support\ConversationCity::noneEquivalentPipelineIds(), true)) {
            $ciudadId = null;
        }

        return [
            'zonas_con_cobertura' => array_keys($porZona),
            'zona_del_cliente'    => $ciudadId ? $this->nombreDeCiudad($ciudadId) : null,
            'por_zona'            => $porZona,
            'nota'                => 'Productos y precios vigentes por zona. Ofrecé solo estos, con el precio de la zona del cliente. '
                .'Una zona de zonas_con_cobertura SIEMPRE tiene cobertura.',
        ];
    }

    /** Las líneas de un pedido, en la forma que el agente ya conoce. */
    protected function lineasDe($lead): array
    {
        return $lead->products->map(fn ($p) => [
            'product_id' => (int) $p->product_id,
            'name'       => $p->name,
            'quantity'   => (float) $p->quantity,
            'price'      => (float) $p->price,
        ])->values()->all();
    }

    protected function nombreDeCiudad(int $pipelineId): ?string
    {
        static $nombres = [];

        return $nombres[$pipelineId] ??= DB::table('lead_pipelines')
            ->where('id', $pipelineId)
            ->value('name');
    }

    protected function distribuidorDe(int $pipelineId): ?string
    {
        $zonas = $this->asignacion()['zonas'];

        foreach ($zonas as $z) {
            if ($z['pipeline_id'] === $pipelineId) {
                return $z['distribuidor'];
            }
        }

        return null;
    }

    /**
     * Llama a un servicio del módulo Distribuidor sólo si está instalado.
     *
     * El módulo de WhatsApp tiene que poder funcionar sin él: son dos módulos que se
     * instalan por separado, y el inbox no puede dejar de andar porque falte el otro.
     */
    protected function siExiste(string $clase, callable $fn)
    {
        return class_exists($clase) ? $fn(app($clase)) : null;
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
                // Quién lo escribió: contact (cliente), ia (el agente), human (un asesor), sistema.
                'sender'  => $m->sender,
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
