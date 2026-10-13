<?php

namespace Webkul\Product\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Services\Responsables;
use Webkul\Product\Support\Caracteristicas;
use Webkul\Product\Support\Entrega;
use Webkul\Product\Support\ListaPrecios;
use Webkul\Product\Support\Variantes;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\OutboundAttachment;

/**
 * Catálogo y envío de adjuntos de producto para el agente de IA.
 *
 * El agente pide «3 imágenes del SKU CM_00013»; el CRM decide cuáles, en qué
 * orden y por qué vía. **Las rutas de los archivos nunca viajan por el modelo de
 * lenguaje**: lo único que el agente manipula es el SKU, que ya es público
 * porque aparece en las cotizaciones.
 *
 * Es la contraparte de `EnvioController` del proyecto c21.
 *
 * Vive en el módulo Product y no en el de WhatsApp a propósito: ese módulo lo
 * comparte el otro tenant, que no tiene catálogo de imprenta y no debe saber que
 * los productos existen.
 */
class AgenteController extends Controller
{
    /** Largo de `quotes.description` y `quotes.subject` en la base. */
    protected const LARGO_DESCRIPCION = 255;

    /** Cómo se llama en WhatsApp cada tipo nuestro. */
    protected const TIPOS = [
        'imagen'    => 'image',
        'documento' => 'document',
    ];

    public function __construct(
        protected ProductRepository $products,
        protected ProductMediaRepository $medias,
        protected GatewayManager $gateways,
        protected Responsables $responsables,
    ) {}

    /**
     * Qué productos puede ofrecer el agente y qué material tiene cada uno.
     *
     * Se listan solo los que tienen algo que ofrecer —material o precios—: si el
     * agente ve un producto vacío, le promete al cliente fotos que no existen o
     * un precio que nadie cargó.
     */
    public function catalogo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'q'     => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        /*
        | Se lista un producto si tiene algo que ofrecer: material para mandar o
        | precios para cotizar. Antes bastaba con tener adjuntos, pero un
        | producto con lista de precios y sin fotos igual se vende — dejarlo
        | afuera haría que el agente diga que no existe.
        */
        $query = $this->products->getModel()->newQuery()
            ->with(['medias', 'precios'])
            ->where(fn ($q) => $q->has('medias')->orHas('precios'))
            ->limit($datos['limit'] ?? 20);

        if ($termino = trim($datos['q'] ?? '')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$termino}%")
                ->orWhere('sku', 'like', "%{$termino}%"));
        }

        return response()->json([
            'data' => $query->get()->map(fn ($p) => $this->resumen($p))->values(),
        ]);
    }

    /**
     * La ficha completa de un producto, para que el agente la describa.
     */
    public function ficha(string $sku): JsonResponse
    {
        $product = $this->products->getModel()->newQuery()
            ->with(['medias', 'precios'])
            ->where('sku', $sku)
            ->first();

        if (! $product) {
            return response()->json(['message' => "No existe un producto con el SKU {$sku}."], 404);
        }

        return response()->json(array_merge($this->resumen($product), [
            'descripcion' => $product->description,
            /*
            | (object) para que sea SIEMPRE un objeto JSON. Un array PHP vacío se
            | serializa como [] y uno con claves como {}: el agente tendría que
            | manejar dos tipos para el mismo campo.
            */
            'caracteristicas' => (object) $this->caracteristicas($product),
            'precios'         => $product->precios
                ->take((int) config('precios.max_filas_agente', 60))
                ->map(fn ($fila) => $this->fila($fila, array_values((array) ($product->ejes_precio ?? []))))
                ->values(),
        ]));
    }

    /**
     * Qué precio corresponde a lo que el cliente eligió.
     *
     * El agente manda los ejes que logró sacar de la charla —«bolsas de 50 L
     * para un restaurante»— y el CRM resuelve la fila. Es el CRM el que decide
     * el precio, no el modelo: un LLM que multiplica y redondea por su cuenta
     * termina cotizando números que la empresa nunca autorizó.
     *
     * Las tres respuestas posibles son distintas a propósito:
     *
     *   `resuelto`  → una sola fila. El agente puede dar el precio.
     *   `ambiguo`   → faltan ejes. El agente tiene que repreguntar, y se le
     *                 devuelve QUÉ falta para que pregunte eso y no cualquier cosa.
     *   `sin_datos` → la combinación no está cargada. No se inventa un precio
     *                 cercano: se deriva.
     */
    public function precio(Request $request, string $sku): JsonResponse
    {
        $datos = $request->validate([
            'criterios' => 'nullable|array',
            'cantidad'  => 'nullable|integer|min:1',
        ]);

        $product = $this->products->getModel()->newQuery()
            ->with(['precios', 'variantes', 'planta', 'ubicacion.warehouse'])
            ->where('sku', $sku)
            ->first();

        if (! $product) {
            return response()->json(['message' => "No existe un producto con el SKU {$sku}."], 404);
        }

        if ($product->precios->isEmpty()) {
            /*
            | 200 y no 404: el producto existe y tiene precio, solo que es el
            | fijo. Un 404 acá haría que el agente le diga al cliente que el
            | producto no existe, que es falso.
            */
            return response()->json([
                'estado'   => 'resuelto',
                'sku'      => $product->sku,
                'nombre'   => $product->name,
                'precio'   => ['precio' => $product->price, 'valores' => (object) [], 'cotiza' => true],
                'cantidad' => $this->porCantidad(null, $datos['cantidad'] ?? null, (float) $product->price)['cantidad'] ?? null,
            ]);
        }

        $criterios = array_filter(
            (array) ($datos['criterios'] ?? []),
            fn ($v) => is_scalar($v) && trim((string) $v) !== ''
        );

        $ejes = array_values((array) ($product->ejes_precio ?? []));

        $candidatas = ListaPrecios::filtrar($product->precios, $criterios);

        if ($candidatas->count() === 1) {
            return response()->json(array_merge([
                'estado' => 'resuelto',
                'sku'    => $product->sku,
                'nombre' => $product->name,
                'precio' => $this->fila($candidatas->first(), $ejes),
            ],
                $this->porCantidad($candidatas->first(), $datos['cantidad'] ?? null),
                $this->entrega($product, $criterios, $datos['cantidad'] ?? null),
            ));
        }

        if ($candidatas->isEmpty()) {
            return response()->json([
                'estado'  => 'sin_datos',
                'sku'     => $product->sku,
                'nombre'  => $product->name,
                'message' => 'Esa combinación no tiene precio cargado. No inventes uno: derivá la consulta a un asesor.',
                'ejes'    => $product->ejes_precio ?? [],
            ]);
        }

        $faltan = $this->ejesQueFaltan($product, $candidatas, $criterios);

        return response()->json([
            'estado'     => 'ambiguo',
            'sku'        => $product->sku,
            'nombre'     => $product->name,
            'message'    => 'Faltan datos para dar un precio. Preguntale al cliente por los ejes que faltan.',
            'faltan'     => $faltan,
            'opciones'   => $this->opcionesPorEje($candidatas, $faltan),
            'candidatas' => $candidatas->map(fn ($f) => $this->fila($f, $ejes))->values(),
        ]);
    }

    /**
     * Pre-elabora la cotización en el CRM al cerrar la toma de interés.
     *
     * Es el PASO 5 del flujo hecho dato: hasta ahora el agente decía «hemos
     * registrado tu información» y lo único que quedaba era la conversación, que
     * el asesor tenía que leer entera para reconstruir qué quería el cliente.
     *
     * Queda una cotización de verdad, con su ítem, su cantidad y su precio, para
     * que el asesor la revise y la mande — no para que salga sola.
     *
     * **El precio y el total los pone el CRM**, no el agente: se resuelven acá
     * desde la misma lista que contestó `precio()`. Si el agente pudiera mandar
     * el monto, un error del modelo se convertiría en una cotización formal con
     * un número que la empresa nunca autorizó.
     */
    public function cotizacion(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'sku'       => 'required|string',
            'criterios' => 'nullable|array',
            'cantidad'  => 'required|integer|min:1',
            'nit'       => 'nullable|string|max:60',
            'empresa'   => 'nullable|string|max:120',
            'marca'     => 'nullable|string|max:60',
            // 400 y no 1000: `quotes.description` es varchar(255) y MySQL está
            // en modo no estricto, así que lo que sobra se pierde en silencio.
            // Se recorta acá, avisando, en vez de dejar que lo corte la base.
            'notas'     => 'nullable|string|max:400',
            // La ciudad del cliente decide quién lo atiende. No se toma de la
            // «Zona» del precio porque esa colapsa La Paz y Cochabamba en
            // «Interior», y ahí cada una tiene su propio asesor.
            'ciudad'    => 'nullable|string|max:60',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $product = $this->products->getModel()->newQuery()
            ->with('precios')
            ->where('sku', $datos['sku'])
            ->first();

        if (! $product) {
            return response()->json(['message' => "No existe un producto con el SKU {$datos['sku']}."], 404);
        }

        [$fila, $error, $pendiente] = $this->filaParaCotizar($product, (array) ($datos['criterios'] ?? []));

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        /*
        | Sin precio cargado la cotización se crea igual, en 0 y marcada como
        | pendiente: el asesor pone el precio y recién ahí se puede enviar
        | (QuoteController la bloquea mientras el total sea 0). Antes se
        | rechazaba con 422 y, con el Stretch Film sin precios, el cliente daba
        | todos sus datos y no quedaba nada.
        */
        $precio = $pendiente !== null ? 0.0 : ($fila ? (float) $fila->precio : (float) $product->price);
        $total = round($precio * $datos['cantidad'], 2);

        // Entrega y variante: lo mismo que propone la pantalla, para que las dos
        // vías produzcan la misma cotización.
        $variante = Variantes::resolver($product->loadMissing('variantes'), (array) ($datos['criterios'] ?? []));

        $entrega = Entrega::paraItem($product, $datos['cantidad'], $variante);

        /*
        | La persona sale de la conversación, no del cuerpo del pedido: el agente
        | no elige a qué contacto se le carga una cotización. Sin persona no hay
        | a quién cotizarle y se corta acá, en vez de crear una huérfana que
        | nadie va a encontrar.
        */
        if (! $conversation->person_id) {
            return response()->json([
                'message' => 'Esta conversación todavía no tiene un contacto asociado en el CRM. '
                    .'Registrá primero los datos del cliente.',
            ], 422);
        }

        /*
        | Round robin: el asesor sale de la matriz producto × ciudad (local
        | primero, nacional si no hay local) y queda escrito en el contacto y el
        | lead, así el handoff que viene después cae en la misma persona. Si
        | nadie atiende esa combinación, se conserva lo que había.
        */
        $ciudad = $this->ciudadDe($datos);

        $asesor = $this->responsables->asignar($conversation, $product, $ciudad);

        $quote = DB::transaction(function () use ($conversation, $product, $fila, $datos, $precio, $total, $entrega, $asesor, $ciudad, $pendiente) {
            $quote = app(\Webkul\Quote\Repositories\QuoteRepository::class)->create([
                // El repositorio lo usa para saber qué atributos personalizados
                // guardar; sin esto no llega el `nit`.
                'entity_type' => 'quotes',
                'subject'     => $this->asuntoDe($product, $fila),
                'description' => $this->descripcionDe($product, $fila, $datos, $pendiente),
                'person_id'   => $conversation->person_id,
                // La oportunidad NO se vincula acá: `quotes` no tiene columna
                // `lead_id`, la relación es la tabla `lead_quotes`. Se engancha
                // después de crear, igual que hace el CRM en su controlador.

                // El que le toca por producto y ciudad; si no hay, el asignado
                // a la conversación o el dueño del contacto si son asesores, y
                // si no, quien atiende la ciudad o el asesor por defecto. Antes
                // terminaba en `?: 1`: el administrador.
                'user_id'      => $asesor?->id ?: $this->asesorDeRespaldo($conversation, $ciudad),
                'expired_at'   => now()->addDays((int) config('cotizacion.validez_dias', 10))->toDateString(),
                'sub_total'    => $total,
                'grand_total'  => $total,
                'nit'          => $datos['nit'] ?? null,
                'razon_social' => $datos['empresa'] ?? null,

                /*
                | La marca decide el logo y el color del PDF. Llega por nombre
                | («Magia Verde») y se guarda como id de opción: el agente no
                | tiene por qué conocer los ids de una tabla del CRM.
                |
                | Sin marca la cotización sale con el azul y el logo por
                | defecto, que es lo que pasaba hasta ahora.
                */
                'marca' => $this->opcionDe('marca', $datos['marca'] ?? null),

                /*
                | Condiciones propuestas desde el producto y la cantidad, igual
                | que en pantalla. Un asesor las revisa antes de mandar: es un
                | borrador, no una cotización final.
                */
                'tiempo_entrega' => $entrega['tiempo'],
                'lugar_entrega'  => $this->opcionDe('lugar_entrega', $entrega['lugar']),

                /*
                | La ciudad del cliente y la planta de la que sale el producto.
                | La ciudad se guarda como opción —el listener Ciudad la copia
                | después al contacto— y la sucursal es el id del almacén, que
                | es lo que espera un atributo `lookup`.
                */
                'ciudad'         => $this->opcionDe('ciudad', $ciudad),
                'sucursal'       => $product->warehouse_id,
                'forma_pago'     => $this->opcionDe('forma_pago', config('cotizacion.forma_pago_default')),

                'items' => [[
                    'product_id' => $product->id,
                    'sku'        => $product->sku,
                    'name'       => $this->asuntoDe($product, $fila),
                    // Los códigos los copia el repositorio desde la variante.
                    'criterios'  => (array) ($datos['criterios'] ?? []),
                    'quantity'   => $datos['cantidad'],
                    'price'      => $precio,
                    'total'      => $total,
                ]],
            ]);

            /*
            | El evento va acá y no lo dispara el repositorio: es lo que hace que
            | el NIT viaje entre la cotización y la ficha del contacto, igual que
            | cuando la carga un asesor a mano.
            */
            /*
            | Vincular la cotización a la oportunidad de la conversación.
            |
            | Sin esto la cotización existía suelta: no aparecía en la pestaña
            | «Cotizaciones» del pedido, y el asesor que abría la oportunidad
            | veía la charla pero no el documento que salió de ella.
            */
            if ($conversation->lead_id) {
                $quote->leads()->syncWithoutDetaching([$conversation->lead_id]);
            }

            \Illuminate\Support\Facades\Event::dispatch('quote.create.after', $quote);

            return $quote;
        });

        if ($conversation->lead_id) {
            $this->aSolicitudRecibida((int) $conversation->lead_id);
        }

        /*
        | Se devuelven las LÍNEAS creadas, no solo el id.
        |
        | El agente no tenía forma de confirmar que la cotización quedó con su
        | producto: `GET /api/v1/quotes/{id}` —el endpoint genérico de Krayin—
        | no incluye los ítems en su respuesta, así que parecía vacía aunque no
        | lo estuviera. Devolverlas acá cierra el turno sin una segunda llamada.
        */
        return response()->json([
            'message'  => $pendiente !== null
                ? 'Cotización pre-elaborada con precio pendiente. Un asesor completa el precio y la envía.'
                : 'Cotización pre-elaborada. Un asesor la revisa antes de enviarla.',
            'quote_id'         => $quote->id,
            'precio_pendiente' => $pendiente !== null,
            'asunto'           => $quote->subject,
            'cantidad'         => $datos['cantidad'],
            'precio'           => $precio,
            'total'            => $total,
            'minimo'           => $fila?->minimo,
            'entrega'          => $entrega,
            'items'            => $this->itemsDe($quote),
            // Para que el agente pueda decirle al cliente quién lo va a llamar.
            'asesor'   => $asesor ? $this->asesor($asesor) : null,
        ], 201);
    }

    /**
     * Con la cotización creada, el pedido pasa a SOLICITUD RECIBIDA.
     *
     * La etapa se busca por código dentro del pipeline del propio pedido (cada
     * ciudad tiene el suyo, con ids distintos). Solo avanza desde NO ATENIDOS:
     * un pedido que ya está más adelante no retrocede por una cotización nueva.
     * Va por el repositorio y con los mismos eventos que el arrastre en el
     * kanban, así se registran las fechas de cambio de etapa.
     */
    protected function aSolicitudRecibida(int $leadId): void
    {
        $lead = \Webkul\Lead\Models\LeadProxy::modelClass()::find($leadId);

        if (! $lead || $lead->stage?->code !== 'no-atenidos') {
            return;
        }

        $destino = $lead->pipeline?->stages()->where('code', 'solicitud-recibida')->first();

        if (! $destino) {
            return;
        }

        \Illuminate\Support\Facades\Event::dispatch('lead.update.before', $lead->id);

        $lead = app(\Webkul\Lead\Repositories\LeadRepository::class)->update([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $destino->id,
        ], $lead->id, ['lead_pipeline_stage_id']);

        \Illuminate\Support\Facades\Event::dispatch('lead.update.after', $lead);
    }

    /**
     * Quién atiende un producto en una ciudad, en el orden en que les toca.
     *
     * Es una consulta: no mueve el turno. Sirve para que el agente pueda decir
     * «lo atiende Fabio Sandoval, de 08:00 a 19:00» antes de derivar, o para
     * contestar «¿con quién hablo por hules en La Paz?». La asignación real
     * pasa al crear la cotización o al derivar.
     *
     * @OA\Get(
     *     path="/api/v1/productos/responsables",
     *     operationId="productosResponsables",
     *     tags={"Productos"},
     *     summary="Quién atiende un producto en una ciudad",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="sku", in="query", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="ciudad", in="query", required=false, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Responsables por turno"),
     *     @OA\Response(response=404, description="SKU inexistente")
     * )
     */
    public function responsablesDe(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'sku'    => 'required|string',
            'ciudad' => 'nullable|string|max:60',
        ]);

        $product = $this->products->getModel()->newQuery()->where('sku', $datos['sku'])->first();

        if (! $product) {
            return response()->json(['message' => "No existe un producto con el SKU {$datos['sku']}."], 404);
        }

        $ciudad = \Webkul\Admin\Services\CiudadCliente::canonica($datos['ciudad'] ?? null);

        $candidatos = $this->responsables->candidatos($product, $ciudad);

        return response()->json([
            'producto'     => ['sku' => $product->sku, 'nombre' => $product->name],
            'ciudad'       => $ciudad,
            // 'local' si alguien de la ciudad lo atiende; 'nacional' si cayó en
            // la cobertura Bolivia; null si nadie.
            'cobertura'    => $candidatos->isEmpty() ? null : ($candidatos->first()->esNacional() ? 'nacional' : 'local'),
            'horario'      => config('cotizacion.horario_atencion'),
            'siguiente'    => $candidatos->isEmpty() ? null : $this->asesor($candidatos->first()->user),
            'responsables' => $candidatos->map(fn ($r) => $this->asesor($r->user) + ['cobertura' => $r->ciudad])->values(),
        ]);
    }

    /**
     * La ciudad del pedido: `ciudad` si vino; si no, la «Zona» de los
     * criterios cuando es una ciudad y no «Interior».
     */
    protected function ciudadDe(array $datos): ?string
    {
        if (! empty($datos['ciudad'])) {
            return \Webkul\Admin\Services\CiudadCliente::canonica($datos['ciudad']);
        }

        $zona = collect((array) ($datos['criterios'] ?? []))
            ->first(fn ($v, $k) => ListaPrecios::comparable((string) $k) === 'zona');

        foreach ((array) config('cotizacion.ciudades_cliente', []) as $ciudad) {
            if ($zona && Responsables::mismaCiudad((string) $zona, $ciudad)) {
                return $ciudad;
            }
        }

        return null;
    }

    /**
     * Un asesor como lo ve el agente: lo justo para presentarlo al cliente.
     */
    protected function asesor($user): array
    {
        return [
            'id'       => $user->id,
            'nombre'   => $user->name,
            'email'    => $user->email,
            'telefono' => $user->telefono,
            'horario'  => $user->horario ?: sprintf(
                'de %s a %s',
                config('cotizacion.horario_atencion.desde', '08:00'),
                config('cotizacion.horario_atencion.hasta', '19:00')
            ),
        ];
    }

    /**
     * La cotización como la necesita el agente, con sus líneas.
     *
     * Existe porque `GET /api/v1/quotes/{id}` de Krayin no devuelve los ítems
     * —su recurso no los incluye— y además responde 500 cuando la cotización no
     * existe, en vez de 404: le pasa `null` al Resource y este intenta leerle el
     * id. Acá el 404 es un 404.
     */
    public function verCotizacion(string $id): JsonResponse
    {
        $quote = \Webkul\Quote\Models\QuoteProxy::modelClass()::with(['items', 'person', 'user'])->find($id);

        if (! $quote) {
            return response()->json(['message' => "No existe la cotización {$id}."], 404);
        }

        return response()->json([
            'quote_id'     => $quote->id,
            'asunto'       => $quote->subject,
            'cliente'      => $quote->person?->name,
            'razon_social' => $quote->razon_social,
            'nit'          => $quote->nit,
            'asesor'       => $quote->user?->name,
            'vence'        => $quote->expired_at,
            'total'        => $quote->grand_total,
            'items'        => $this->itemsDe($quote),
        ]);
    }

    /**
     * Las líneas de una cotización, con la foto de la variante que guardaron.
     */
    protected function itemsDe($quote): array
    {
        return $quote->items()->get()->map(fn ($item) => [
            'sku'          => $item->sku,
            'descripcion'  => $item->name,
            'variante'     => (object) ((array) $item->variante),
            'codigo_tr4'   => $item->codigo_tr4,
            'codigo_barra' => $item->codigo_barra,
            'medida'       => $item->medida,
            'unidad'       => $item->unidad,
            'cantidad'     => (int) $item->quantity,
            'precio'       => $item->price,
            'total'        => $item->total,
        ])->values()->all();
    }

    /**
     * El ID de la opción de un atributo select, a partir de su nombre.
     *
     * Los selects guardan el id de `attribute_options`, no el texto: mandar
     * «A domicilio» se guarda como 0 y el PDF sale sin lugar de entrega.
     */
    protected function opcionDe(string $code, ?string $nombre): ?int
    {
        if (! $nombre) {
            return null;
        }

        $attribute = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
            ->getAttributeByCode($code, 'quotes');

        if (! $attribute) {
            return null;
        }

        // Tolerante con acentos y mayúsculas, como el resto de lo que manda el
        // agente: «magia verde» tiene que encontrar «Magia Verde».
        $buscado = ListaPrecios::comparable($nombre);

        return $attribute->options
            ->first(fn ($o) => ListaPrecios::comparable((string) $o->name) === $buscado)
            ?->id;
    }

    /**
     * La fila de precio con la que se cotiza, o por qué no se puede.
     *
     * Se exige una sola combinación: una cotización formal con el precio de «la
     * más parecida» es peor que no tener cotización. Si faltan ejes es error (el
     * agente tiene que preguntarlos); si la combinación no tiene precio o no
     * está en la lista, se cotiza igual con precio pendiente y el motivo.
     *
     * @return array{0: ?object, 1: ?string, 2: ?string} fila, error, motivo del precio pendiente
     */
    protected function filaParaCotizar($product, array $criterios): array
    {
        if ($product->precios->isEmpty()) {
            return [null, null, null]; // precio fijo del producto
        }

        $criterios = array_filter($criterios, fn ($v) => is_scalar($v) && trim((string) $v) !== '');

        $candidatas = ListaPrecios::filtrar($product->precios, $criterios);

        if ($candidatas->isEmpty()) {
            return [null, null, 'La combinación pedida no está en la lista de precios.'];
        }

        if ($candidatas->count() > 1) {
            $faltan = implode(', ', $this->ejesQueFaltan($product, $candidatas, $criterios));

            return [null, "Faltan datos para cotizar: {$faltan}. Preguntaselos al cliente antes de cerrar.", null];
        }

        $fila = $candidatas->first();

        if (! $fila->cotiza()) {
            return [$fila, null, $fila->nota ?: 'Sin precio cargado para esta combinación.'];
        }

        return [$fila, null, null];
    }

    /**
     * El dueño de una cotización cuando la matriz no dio a nadie para ese
     * producto y ciudad: el asignado de la conversación o el dueño del
     * contacto si son asesores; si no, quien atiende la ciudad o el asesor
     * por defecto. Nunca un administrador.
     */
    protected function asesorDeRespaldo($conversation, ?string $ciudad): ?int
    {
        foreach ([$conversation->assigned_user_id, $conversation->person?->user_id] as $candidato) {
            if ($this->responsables->esAsesor($candidato ? (int) $candidato : null)) {
                return (int) $candidato;
            }
        }

        return $this->responsables->porCiudad($ciudad ?: $this->responsables->ciudadDelContacto($conversation))?->id;
    }

    protected function asuntoDe($product, $fila): string
    {
        $combo = $this->comboDe($product, $fila);

        $asunto = $combo ? "{$product->name} — {$combo}" : $product->name;

        // Mismo motivo que la descripción: la columna corta en silencio.
        return mb_strimwidth($asunto, 0, self::LARGO_DESCRIPCION, '…');
    }

    /**
     * La combinación en el orden en que se declararon los ejes.
     *
     * No se usa `$fila->etiqueta` porque esa lee `valores` tal como vuelve de la
     * base, y MySQL normaliza el orden de las claves de una columna JSON: el
     * asunto de la cotización salía «Santa Cruz · HORECA · 50 L» en vez de
     * «50 L · HORECA · Santa Cruz», que es como lo nombra el vendedor.
     */
    protected function comboDe($product, $fila): ?string
    {
        if (! $fila) {
            return null;
        }

        $valores = $this->valoresOrdenados($fila, array_values((array) ($product->ejes_precio ?? [])));

        $valores = array_filter($valores, fn ($v) => $v !== null && $v !== '');

        return $valores ? implode(' · ', $valores) : null;
    }

    /**
     * El detalle que el asesor lee antes de mandar la cotización.
     *
     * Incluye lo que el agente recogió y no entra en ningún campo —la empresa,
     * las notas de la charla— para que no haya que volver a leer la conversación
     * entera. El mínimo se repite acá a propósito: es lo que más se olvida al
     * revisar y lo que invalida el pedido.
     */
    protected function descripcionDe($product, $fila, array $datos, ?string $pendiente = null): string
    {
        /*
        | `quotes.description` es varchar(255) y la conexión no está en modo
        | estricto: todo lo que pase de ahí lo corta MySQL sin decir nada. Se
        | arma con presupuesto para que lo que se pierda sea lo MENOS importante
        | y no lo último que se escribió.
        |
        | El orden es de mayor a menor valor para el asesor: lo que el cliente
        | dijo no se puede reconstruir; la nota del catálogo sí, mirando el
        | producto. Por eso las notas van antes.
        */
        $lineas = $pendiente !== null
            ? ['PRECIO PENDIENTE: completar antes de enviar. Pre-elaborada por el agente de IA.']
            : ['Pre-elaborada por el agente de IA.'];

        if ($empresa = ($datos['empresa'] ?? null)) {
            $lineas[] = "Empresa: {$empresa}";
        }

        if ($combo = $this->comboDe($product, $fila)) {
            $lineas[] = "Variante: {$combo}";
        } elseif ($pedido = array_filter((array) ($datos['criterios'] ?? []), 'is_scalar')) {
            // Sin fila no hay combinación del catálogo: queda lo que pidió el cliente.
            $lineas[] = 'Pedido: '.implode(' · ', $pedido);
        }

        $lineas[] = 'Cantidad: '.$datos['cantidad'].($fila?->unidad ? " ({$fila->unidad})" : '');

        if ($fila?->minimo) {
            $lineas[] = "Mínimo: {$fila->minimo}";
        }

        if ($notas = trim((string) ($datos['notas'] ?? ''))) {
            $lineas[] = "Notas: {$notas}";
        }

        if ($fila?->nota) {
            $lineas[] = "Catálogo: {$fila->nota}";
        } elseif ($pendiente !== null) {
            $lineas[] = $pendiente;
        }

        return static::recortar($lineas, static::LARGO_DESCRIPCION);
    }

    /**
     * Une las líneas sin pasarse del largo de la columna.
     *
     * Se agregan enteras mientras entren; la primera que no entra se recorta con
     * puntos suspensivos y ahí se corta. El «…» es lo que distingue un dato
     * incompleto de uno que nunca se cargó: sin él, el asesor lee una frase que
     * termina bien y no tiene forma de saber que le falta la mitad.
     */
    protected static function recortar(array $lineas, int $maximo): string
    {
        $salida = '';

        foreach ($lineas as $linea) {
            $candidato = $salida === '' ? $linea : $salida."\n".$linea;

            if (mb_strlen($candidato) <= $maximo) {
                $salida = $candidato;

                continue;
            }

            $espacio = $maximo - mb_strlen($salida) - 2; // el salto y el «…»

            if ($espacio > 12) {
                $salida .= "\n".mb_substr($linea, 0, $espacio).'…';
            }

            break;
        }

        return $salida;
    }

    /**
     * Manda al cliente los adjuntos de un producto.
     */
    public function media(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'sku'       => 'required|string',
            'tipo'      => 'required|in:imagen,documento,enlace',
            'cantidad'  => 'nullable|integer|min:1',
            // Los mismos criterios que `precio`: con ellos se manda el adjunto
            // de ESA variante (la ficha de 50 L), no todos los del producto.
            'criterios' => 'nullable|array',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        if ($bloqueo = $this->bloqueoPara($conversation)) {
            return $bloqueo;
        }

        $product = $this->products->getModel()->newQuery()->with('variantes')->where('sku', $datos['sku'])->first();

        if (! $product) {
            return response()->json(['message' => "No existe un producto con el SKU {$datos['sku']}."], 404);
        }

        $variante = ! empty($datos['criterios'])
            ? Variantes::resolver($product, (array) $datos['criterios'])
            : null;

        /*
        | Dos guardas para que NUNCA salgan las cinco fichas de Magia Verde
        | cuando el cliente pidió una. Pasó el 21/09: sin variante resuelta el
        | endpoint mandaba todo en orden de carga (35 L, 200 L, 50 L…).
        |
        |  1. Criterios que no identifican una variante: 422, no «todo». Un
        |     «50 litros» mal escrito no puede convertirse en cinco PDFs.
        |  2. Sin criterios, si este tipo de adjunto tiene versiones por
        |     variante: 422 pidiendo la variante. Los adjuntos generales (la
        |     foto de portada) siguen saliendo sin criterios, como siempre.
        */
        if (! empty($datos['criterios']) && ! $variante) {
            return response()->json([
                'message'   => "Los criterios no identifican una variante de {$datos['sku']}. Preguntá cuál y volvé a pedir.",
                'variantes' => $product->variantes->map(fn ($v) => Variantes::etiqueta($product, $v))->values(),
            ], 422);
        }

        if (! $variante && $this->medias->tieneVersionesPorVariante($product->id, $datos['tipo'])) {
            return response()->json([
                'message'   => "{$datos['sku']} tiene {$datos['tipo']}s distintos por variante. Preguntale al cliente cuál y mandá `criterios`.",
                'variantes' => $product->variantes->map(fn ($v) => Variantes::etiqueta($product, $v))->values(),
            ], 422);
        }

        $archivos = $this->medias->paraEnviar($product->id, $datos['tipo'], $datos['cantidad'] ?? null, $variante?->id);

        // Queda en el log lo que pidió el agente y qué se resolvió: cuando algo
        // sale mal, el cuerpo del POST no está en ningún otro lado.
        \Illuminate\Support\Facades\Log::info('Agente: material pedido', [
            'conversation' => $conversation->id,
            'sku'          => $datos['sku'],
            'tipo'         => $datos['tipo'],
            'cantidad'     => $datos['cantidad'] ?? null,
            'criterios'    => $datos['criterios'] ?? null,
            'variante'     => $variante ? Variantes::etiqueta($product, $variante) : null,
            'archivos'     => $archivos->pluck('path')->map(fn ($p) => basename((string) $p))->all(),
        ]);

        if ($archivos->isEmpty()) {
            /*
            | 404 y no 200 con cero enviados: el agente ya le dijo al cliente «te
            | mando las fotos» y necesita saber que no salió ninguna para poder
            | corregirse en el mismo turno.
            */
            $plural = ['imagen' => 'imágenes', 'documento' => 'documentos', 'enlace' => 'enlaces'][$datos['tipo']];

            $de = $variante
                ? ' para la variante «'.Variantes::etiqueta($product, $variante).'»'
                : '';

            return response()->json([
                'message' => "El producto {$datos['sku']} no tiene {$plural}{$de} cargados en el catálogo.",
            ], 404);
        }

        // Se valida TODO antes de encolar: un rechazo a mitad de camino deja al
        // cliente con media ficha y sin forma de saber qué faltó.
        foreach ($archivos as $media) {
            if ($media->esArchivo() && $error = $this->rechazoPorLimite($media)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $mensajes = DB::transaction(function () use ($conversation, $archivos) {
            $creados = [];

            foreach ($archivos as $media) {
                $creados[] = $media->esArchivo()
                    ? $this->mensajeArchivo($conversation, $media)
                    : $this->mensajeTexto($conversation, $media->url);
            }

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $creados;
        });

        // Encadenados: el orden de la galería es el orden en que el cliente los
        // tiene que ver, y workers en paralelo no garantizan ninguno.
        Bus::chain(array_map(fn ($m) => new SendMessage($m->id), $mensajes))
            ->onQueue(config('whatsapp.queue'))
            ->dispatch();

        return response()->json([
            'message'  => 'Enviado.',
            'enviados' => count($mensajes),
            'sku'      => $product->sku,
            // Qué variante se resolvió, para que el agente pueda decir «te
            // mandé la ficha de 50 L» y no «te mandé la ficha».
            'variante' => $variante ? Variantes::etiqueta($product, $variante) : null,
            // 'variante' = son los adjuntos de esa variante; 'producto' = no
            // tenía propios y salieron los generales.
            'alcance'  => $variante && $archivos->first()->variante_id ? 'variante' : 'producto',
        ]);
    }

    /**
     * Las mismas defensas que tiene el envío de texto del agente.
     *
     * La respuesta del agente viaja lejos —CRM → webhook → n8n piensa → POST de
     * vuelta acá— y en ese hueco puede haber ocurrido una derivación. Se rechaza
     * el envío tardío en vez de dejar que la IA hable por encima del asesor.
     */
    protected function bloqueoPara($conversation): ?JsonResponse
    {
        if ($conversation->handoffOpen() || ! $conversation->aiEffective()) {
            return response()->json([
                'message' => 'Esta conversación fue derivada a un asesor. El agente ya no debe enviar material.',
            ], 409);
        }

        $window = MessagingWindow::compute(
            $this->gateways->for($conversation)->messagingWindowHours(),
            $conversation->last_inbound_at,
        );

        if ($window->applies && ! $window->open) {
            return response()->json([
                'message' => 'Fuera de la ventana de 24h de WhatsApp. No se puede enviar material hasta que el cliente escriba.',
            ], 422);
        }

        return null;
    }

    /**
     * Los límites de envío son los de Meta, no los de carga.
     *
     * Un PDF de 25 MB entra al CRM sin problema pero no siempre sale por
     * WhatsApp. Rechazarlo acá convierte un Graph 400 opaco en un mensaje que el
     * agente puede explicarle al cliente.
     */
    protected function rechazoPorLimite($media): ?string
    {
        $tipo = self::TIPOS[$media->tipo];

        $limites = (array) config('whatsapp.media.'.$tipo);

        if (! Storage::disk('local')->exists($media->path)) {
            return 'Un archivo del producto ya no está en el servidor. Avisá al asesor.';
        }

        $kb = Storage::disk('local')->size($media->path) / 1024;

        if (isset($limites['max_kb']) && $kb > $limites['max_kb']) {
            return 'Un archivo supera el límite de WhatsApp para '.$tipo
                .' ('.round($limites['max_kb'] / 1024, 1).' MB).';
        }

        $mime = Storage::disk('local')->mimeType($media->path);

        if (! empty($limites['mimes']) && ! in_array($mime, $limites['mimes'], true)) {
            return 'WhatsApp no acepta el formato de alguno de los archivos como '.$tipo.'.';
        }

        return null;
    }

    protected function mensajeArchivo($conversation, $media)
    {
        $file = app(OutboundAttachment::class)->stageContents(
            Storage::disk('local')->get($media->path),
            basename($media->path),
            Storage::disk('local')->mimeType($media->path),
            $conversation->id,
        );

        return MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => self::TIPOS[$media->tipo],
            /*
            | Sin caption a propósito: el agente manda su propia descripción como
            | texto aparte. Con un pie acá el cliente ve dos descripciones —una
            | pegada a la primera imagen y la general abajo— y queda un sándwich
            | «descripción, fotos, descripción».
            */
            'body'            => null,
            'media_path'      => $file['url'],
            'media_mime'      => $file['mime'],
            'media_name'      => $file['name'],
            'status'          => 'queued',
            'sender'          => 'ia',
            'payload'         => ['media_disk_path' => $file['path']],
        ]);
    }

    protected function mensajeTexto($conversation, string $texto)
    {
        return MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'body'            => $texto,
            'status'          => 'queued',
            'sender'          => 'ia',
        ]);
    }

    /**
     * Lo que el agente ve de un producto.
     *
     * `adjuntos` son CONTEOS por tipo, no rutas: el agente necesita saber que
     * hay 5 imágenes para decidir cuántas pedir, y no necesita —ni debe— ver
     * dónde están.
     *
     * `precio_variable` le dice al agente que no puede cotizar todavía y qué
     * tiene que preguntar antes (`ejes_precio`).
     */
    protected function resumen($product): array
    {
        $porTipo = $product->medias->groupBy('tipo')->map->count();

        $variable = $product->precios->isNotEmpty();

        return [
            'sku'    => $product->sku,
            'nombre' => $product->name,
            /*
            | `precio` en null cuando el producto se cotiza por variante. Dejar
            | el `price` de la tabla (que en esos productos es 0) haría que el
            | agente ofrezca bolsas a 0 Bs, que es el peor error posible acá.
            */
            'precio'          => $variable ? null : $product->price,
            'precio_variable' => $variable,
            'ejes_precio'     => $variable ? array_values((array) ($product->ejes_precio ?? [])) : [],
            'adjuntos'        => [
                'imagen'    => (int) ($porTipo['imagen'] ?? 0),
                'documento' => (int) ($porTipo['documento'] ?? 0),
                'enlace'    => (int) ($porTipo['enlace'] ?? 0),
            ],
        ];
    }

    /**
     * Cómo y cuándo se entrega lo que el cliente eligió.
     *
     * Sale del producto y de la cantidad, no del modelo: sin esto el agente no
     * tenía forma de decir «desde 50 rollos lo llevamos a domicilio» sin
     * inventarlo, que es lo mismo que hacemos con los precios.
     *
     * Sin cantidad se devuelve igual, con el lugar base: el cliente pregunta
     * «¿me lo llevan?» antes de decidir cuánto lleva.
     */
    protected function entrega($product, array $criterios, ?int $cantidad): array
    {
        $variante = Variantes::resolver($product, $criterios);

        $entrega = Entrega::paraItem($product, $cantidad ?? 0, $variante);

        // El umbral se nombra siempre que exista: es lo que le permite al agente
        // ofrecer «si lleva 50 o más, se lo llevamos».
        if ($product->entrega_domicilio_desde) {
            $entrega['domicilio_desde'] = (int) $product->entrega_domicilio_desde;
        }

        /*
        | La sucursal, con su enlace de Maps, cuando el cliente tiene que ir a
        | buscarlo. «Recoge en planta» sin dirección deja al agente sin poder
        | contestar la pregunta que viene justo después: ¿dónde queda?
        |
        | Solo cuando aplica: en un pedido que se entrega a domicilio, mandar la
        | dirección de la fábrica confunde.
        */
        if ($entrega['lugar'] === Entrega::LUGAR_PLANTA && $sucursal = $this->sucursalDe($product)) {
            $entrega['sucursal'] = $sucursal;
        }

        return ['entrega' => $entrega];
    }

    /**
     * La sucursal de un producto, como la necesita el agente.
     *
     * Primero la ubicación de la planta («Santa Cruz · Tapas», con su enlace
     * de Maps). Sin ubicación cargada, el almacén con sus atributos `linea` y
     * `maps_url`, que es como estaba antes de tener un solo almacén.
     */
    protected function sucursalDe($product): ?array
    {
        if ($ubicacion = $product->ubicacion) {
            return array_filter([
                'nombre' => $ubicacion->warehouse ? $ubicacion->warehouse->name.' · '.$ubicacion->name : $ubicacion->name,
                'maps'   => $ubicacion->maps_url,
            ], fn ($v) => $v !== null && $v !== '');
        }

        $planta = $product->planta;

        if (! $planta) {
            return null;
        }

        return array_filter([
            'nombre' => $planta->linea ? $planta->name.' · '.$planta->linea : $planta->name,
            'maps'   => $planta->maps_url,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Lo que sale de multiplicar el precio por lo que el cliente lleva.
     *
     * **La cuenta la hace el CRM.** El agente tiene prohibido calcular precios
     * —un modelo que multiplica y redondea por su cuenta termina cotizando
     * números que la empresa nunca autorizó— pero sin esto no tenía forma de dar
     * un total, que es lo primero que pregunta cualquier cliente.
     *
     * El mínimo se compara contra el número que abre el texto de `minimo`
     * («10 unidades» → 10). Se guarda como texto porque la unidad cambia por
     * producto (unidades, cajas, rollos), y el número solo sirve para comparar:
     * lo que se le muestra al cliente es siempre la frase completa.
     */
    protected function porCantidad($fila, ?int $cantidad, ?float $precioFijo = null): array
    {
        if ($cantidad === null) {
            return [];
        }

        $precio = $precioFijo ?? ($fila?->precio !== null ? (float) $fila->precio : null);

        if ($precio === null) {
            // La combinación no se cotiza: un total acá sería inventar el
            // precio que la fila justamente no tiene.
            return [];
        }

        $minimoTexto = $fila?->minimo;

        $minimo = preg_match('/\d+/', (string) $minimoTexto, $m) ? (int) $m[0] : null;

        $cumple = $minimo === null || $cantidad >= $minimo;

        return [
            'cantidad' => [
                'pedida'        => $cantidad,
                'minimo'        => $minimo,
                'minimo_texto'  => $minimoTexto,
                'cumple_minimo' => $cumple,
                'total'         => round($precio * $cantidad, 2),
                'message'       => $cumple
                    ? null
                    : "El pedido mínimo es {$minimoTexto} y el cliente pidió {$cantidad}. "
                        .'Decíselo y preguntale si quiere ajustar la cantidad; no cierres la cotización por debajo del mínimo.',
            ],
        ];
    }

    /**
     * Una fila de la lista de precios como la ve el agente.
     *
     * `precio` puede ser null y eso es información, no un hueco: la fila existe
     * porque la combinación existe, pero no se cotiza por chat. La `nota` dice
     * qué hacer en ese caso, y por eso viaja siempre.
     */
    protected function fila($fila, array $ejes = []): array
    {
        return [
            'valores' => (object) $this->valoresOrdenados($fila, $ejes),
            'precio'  => $fila->precio,
            'unidad'  => $fila->unidad,
            'minimo'  => $fila->minimo,
            'nota'    => $fila->nota,
            'cotiza'  => $fila->cotiza(),
        ];
    }

    /**
     * Los valores en el orden en que el asesor declaró los ejes.
     *
     * MySQL normaliza el orden de las claves de una columna JSON, así que
     * {"Tamaño","Canal"} vuelve como {"Canal","Tamaño"} y la combinación se
     * leería «HORECA · 50 L». Se reordena acá y no al guardar porque el orden
     * correcto es el de los ejes del producto, que puede cambiar después.
     */
    protected function valoresOrdenados($fila, array $ejes): array
    {
        $valores = (array) $fila->valores;

        if (! $ejes) {
            return $valores;
        }

        $ordenados = [];

        foreach ($ejes as $eje) {
            if (array_key_exists($eje, $valores)) {
                $ordenados[$eje] = $valores[$eje];
            }
        }

        // Un valor cargado bajo un eje que ya no existe igual se muestra: es un
        // dato que alguien cargó, y esconderlo lo haría desaparecer sin aviso.
        return $ordenados + $valores;
    }

    /**
     * Los ejes que el agente todavía no preguntó y que separan a las candidatas.
     *
     * Se devuelve solo lo que DISCRIMINA: si todas las candidatas comparten el
     * mismo canal, preguntar el canal no achica nada y hace que el cliente
     * conteste algo que no cambia el precio.
     */
    protected function ejesQueFaltan($product, $candidatas, array $criterios): array
    {
        $yaPreguntados = array_map('mb_strtolower', array_keys($criterios));

        $faltan = [];

        foreach ((array) ($product->ejes_precio ?? []) as $eje) {
            if (in_array(mb_strtolower((string) $eje), $yaPreguntados, true)) {
                continue;
            }

            $distintos = $candidatas
                ->map(fn ($f) => (string) (((array) $f->valores)[$eje] ?? ''))
                ->unique();

            if ($distintos->count() > 1) {
                $faltan[] = $eje;
            }
        }

        return $faltan;
    }

    /**
     * Qué valores puede ofrecerle el agente al cliente por cada eje que falta.
     *
     * Salen de las candidatas y no del producto entero: después de acotar a
     * "50 L", ofrecer los cinco tamaños sería volver a empezar. Y solo de los
     * ejes de `faltan`: devolver también el que el cliente ya contestó invita al
     * modelo a volver a preguntarlo.
     */
    protected function opcionesPorEje($candidatas, array $faltan): object
    {
        $opciones = [];

        foreach ($candidatas as $fila) {
            foreach ((array) $fila->valores as $eje => $valor) {
                if (in_array($eje, $faltan, true)) {
                    $opciones[$eje][(string) $valor] = true;
                }
            }
        }

        return (object) array_map(fn ($v) => array_keys($v), $opciones);
    }

    protected function caracteristicas($product): array
    {
        $valor = $product->{'product_type'} ?? null;

        $campos = Caracteristicas::paraCategorias(
            array_filter(is_array($valor) ? $valor : (array) $valor)
        );

        $salida = [];

        foreach ((array) $product->caracteristicas as $clave => $v) {
            $salida[Caracteristicas::etiqueta($clave, $campos)] = $v;
        }

        return $salida;
    }
}
