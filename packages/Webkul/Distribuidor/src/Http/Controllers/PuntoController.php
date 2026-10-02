<?php

namespace Webkul\Distribuidor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Distribuidor\Models\EntregaProxy;
use Webkul\Distribuidor\Models\PagoProxy;
use Webkul\Distribuidor\Repositories\PagoRepository;
use Webkul\Distribuidor\Services\Alcance;
use Webkul\Distribuidor\Services\Catalogo;
use Webkul\Distribuidor\Services\PuntoPedido;
use Webkul\Distribuidor\Services\Ubicacion;
use Webkul\Lead\Models\LeadProxy;

/**
 * El Punto de Distribuidor.
 *
 * Una sola pantalla: elegir sucursal, buscar o crear el contacto por teléfono,
 * armar el pedido con lo que esa sucursal vende, cobrar y entregar. Todo lo que
 * escribe pasa por los servicios del módulo, que a su vez usan los repositorios
 * del CRM para no saltearse eventos ni ACL.
 */
class PuntoController extends Controller
{
    public function __construct(
        protected Catalogo $catalogo,
        protected PuntoPedido $pedidos,
        protected PagoRepository $pagos,
        protected Alcance $alcance,
        protected Ubicacion $ubicacion,
    ) {}

    public function index()
    {
        return view('distribuidor::punto', [
            /*
             * Sólo las sucursales: son EL selector de lugar de la pantalla, y cada
             * una ya trae resuelta su ciudad. Antes también se pasaba la lista de
             * ciudades para una segunda fila de filtros, que era la misma lista de
             * lugares repetida —y que además mostraba pipelines que no son
             * sucursales reales—.
             *
             * Vienen del alcance y no del catálogo crudo: un distribuidor
             * restringido no ve las sucursales ajenas.
             */
            'sucursales' => $this->alcance->sucursales(),
            'formasPago' => config('distribuidor.formas_de_pago'),
        ]);
    }

    /**
     * El catálogo de una sucursal.
     */
    public function productos(Request $request): JsonResponse
    {
        $data = $request->validate(['sucursal' => 'required|integer']);

        /*
         * La sucursal tiene que estar en su alcance. Sin esto, el filtro de la UI
         * se saltea pidiendo otro id a mano, y con él los precios de otra ciudad.
         */
        $permitidas = array_column($this->alcance->sucursales(), 'id');

        abort_if(! in_array((int) $data['sucursal'], $permitidas, true), 403, 'Esa sucursal no está en tu alcance.');

        return response()->json([
            'productos' => $this->catalogo->productosDe((int) $data['sucursal']),
        ]);
    }

    /**
     * Busca el contacto por teléfono. No crea nada: el Punto muestra lo que
     * encontró y deja que la persona confirme antes de abrir una ficha nueva.
     */
    public function buscarContacto(Request $request): JsonResponse
    {
        $data = $request->validate(['telefono' => 'required|string|min:7|max:32']);

        $persona = app(\Webkul\Contact\Repositories\PersonRepository::class)
            ->findByPhone($data['telefono']);

        return response()->json([
            'encontrado' => (bool) $persona,
            'persona'    => $persona ? $this->persona($persona) : null,
        ]);
    }

    /**
     * Crea el contacto (o devuelve el que ya existía con ese teléfono).
     */
    public function guardarContacto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telefono' => 'required|string|min:7|max:32',
            'nombre'   => 'required|string|max:255',
        ]);

        $r = $this->pedidos->contacto($data['telefono'], $data['nombre']);

        return response()->json([
            'persona' => $this->persona($r['persona']),
            'creada'  => $r['creada'],
        ]);
    }

    /**
     * Abre el pedido con sus productos.
     */
    public function guardarPedido(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_id'              => 'required|integer|exists:persons,id',
            'ciudad_id'              => 'required|integer|exists:lead_pipelines,id',
            'nota'                   => 'nullable|string|max:1000',
            'productos'              => 'required|array|min:1',
            'productos.*.product_id' => 'required|integer|exists:products,id',
            /*
             * Entera, no decimal. `lead_products.quantity` es `int` en la base:
             * validar `numeric` dejaba pasar 2,5 y MySQL lo truncaba a 2 en
             * silencio, con el total del pedido calculado sobre 2,5. Los
             * productos de este catálogo (botellones, bombas) no se fraccionan.
             */
            'productos.*.quantity' => 'required|integer|min:1',
            /*
             * Libre a propósito: el precio viene precargado con el del catálogo
             * de la sucursal, pero el distribuidor puede cambiarlo —un
             * descuento, un precio acordado, un redondeo en mano—.
             */
            'productos.*.price'    => 'required|numeric|min:0',
        ]);

        abort_if(
            ! $this->alcance->puedeVerCiudad((int) $data['ciudad_id']),
            403,
            'Esa ciudad no está en tu alcance.',
        );

        $lead = DB::transaction(fn () => $this->pedidos->crear(
            (int) $data['person_id'],
            (int) $data['ciudad_id'],
            $data['productos'],
            $data['nota'] ?? null,
        ));

        return response()->json(['pedido' => $this->pedido($lead->id)], 201);
    }

    /**
     * Mueve el pedido de etapa: confirmar, entregar, cancelar.
     */
    public function avanzar(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'etapa' => 'required|string|in:'.implode(',', array_keys(config('distribuidor.etapas'))),
            /*
             * Obligatoria para entregar: es la prueba de que el pedido llegó.
             * Cuando el cliente dice que no recibió nada, la fecha sola no alcanza.
             */
            'foto'  => 'required_if:etapa,entregado|image|max:8192',
        ]);

        $lead = LeadProxy::modelClass()::findOrFail($id);

        abort_if(
            ! $this->alcance->puedeVerCiudad((int) $lead->lead_pipeline_id),
            403,
            'Ese pedido no está en tu alcance.',
        );

        $this->pedidos->avanzar((int) $id, config('distribuidor.etapas.'.$data['etapa']));

        /*
         * La entrega se registra acá y no se deduce de la etapa: `updated_at` del
         * lead se mueve con cualquier edición posterior, así que no sirve para
         * contestar "¿cuándo se lo entregaron?" — que es exactamente lo que se
         * pregunta cuando un cliente dice que no le llegó.
         */
        if ($data['etapa'] === 'entregado') {
            EntregaProxy::modelClass()::create([
                'lead_id'      => (int) $id,
                'user_id'      => auth()->guard('user')->id(),
                'entregado_at' => now(),
                // Nombre aleatorio: la ruta de la foto viaja al navegador, y un
                // nombre predecible dejaría ver la entrega de otro pedido.
                'foto_path'    => $request->file('foto')->store('distribuidor/entregas', 'public'),
            ]);
        }

        return response()->json(['pedido' => $this->pedido((int) $id)]);
    }

    /**
     * Registra un cobro. Varios por pedido: un pago parcial es lo normal.
     */
    public function cobrar(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            /*
             * El importe NO viene del cliente: en el Punto siempre se cobra al
             * contado, el total del pedido. Calcularlo en el servidor elimina la
             * posibilidad de que el navegador mande otra cifra —por un bug, por
             * una pantalla vieja en caché o a mano— y deja la regla en un solo
             * lugar en vez de repetida en los dos lados.
             *
             * El precio ya es ajustable al armar el pedido, así que un descuento
             * se hace ahí y el total queda correcto antes de llegar a cobrar.
             */
            'method' => 'required|string|in:'.implode(',', array_keys(config('distribuidor.formas_de_pago'))),
            'note'   => 'nullable|string|max:255',
        ]);

        $lead = LeadProxy::modelClass()::findOrFail($id);

        // Lo que falta cobrar del pedido: al contado es el total.
        $aCobrar = round((float) $lead->lead_value - $this->pagos->cobrado((int) $id), 2);

        abort_if($aCobrar <= 0, 422, 'Ese pedido ya está cobrado.');

        PagoProxy::modelClass()::create([
            'lead_id' => $lead->id,
            'user_id' => auth()->guard('user')->id(),
            'amount'  => $aCobrar,
            'method'  => $data['method'],
            'note'    => $data['note'] ?? null,
        ]);

        return response()->json(['pedido' => $this->pedido((int) $id)]);
    }

    /**
     * Los pedidos abiertos del día, que es la pantalla de trabajo.
     *
     * Del usuario que está adelante del Punto: un distribuidor no necesita —ni
     * debería— ver la caja de otro.
     */
    public function pedidos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ciudad' => 'required|integer',
            'filtro' => 'nullable|string|in:pendientes,hoy',
        ]);

        $filtro = $data['filtro'] ?? 'pendientes';

        $query = LeadProxy::modelClass()::query();

        if ($filtro === 'hoy') {
            // "Hoy" es lo propio: lo que ESTE distribuidor tomó en el día, en esta
            // sucursal (la ciudad la aplica el scope de abajo, para los dos filtros).
            $query->where('user_id', auth()->guard('user')->id())
                ->whereDate('created_at', now()->toDateString());
        } else {
            /*
             * "Para entregar" son SÓLO los confirmados.
             *
             * Un prospecto no es trabajo de reparto: es un pedido que todavía
             * nadie confirmó, y mezclarlo con la ruta del día obliga a decidir
             * en cada tarjeta si corresponde salir con ella o no. El que sale a
             * la calle quiere una lista de cosas que hay que llevar.
             *
             * Los prospectos se confirman desde "Mis pedidos de hoy", que es
             * donde aparece lo que el distribuidor acaba de cargar.
             *
             * Es trabajo de la ciudad y no de una persona, así que no se filtra
             * por dueño. Sin fecha tampoco: un pedido de antes de ayer que sigue
             * sin entregar es más urgente, no menos.
             */
            $query->whereHas('stage', fn ($q) => $q->where(
                'code',
                config('distribuidor.etapas.confirmado'),
            ));
        }

        /*
         * El pedido se ve SÓLO desde su propia sucursal.
         *
         * Los de Jujuy no ven los de Palpalá ni al revés: son operaciones
         * distintas, con su propio catálogo y sus propios precios, y un pedido de
         * otra ciudad en la lista es ruido que alguien puede llegar a tocar.
         *
         * La ciudad es obligatoria, no un filtro opcional: sin ella la consulta
         * devolvería todo, que es justamente lo que no debe pasar. El Punto la
         * manda siempre desde el selector del encabezado.
         */
        abort_if(empty($data['ciudad']), 422, 'Falta la sucursal.');

        abort_if(
            ! $this->alcance->puedeVerCiudad((int) $data['ciudad']),
            403,
            'Esa sucursal no está en tu alcance.',
        );

        $query->where('lead_pipeline_id', (int) $data['ciudad']);

        $ids = $query->orderByDesc('id')->limit(100)->pluck('id')->all();

        return response()->json([
            'pedidos' => array_map(fn ($id) => $this->pedido($id), $ids),
            'filtro'  => $filtro,
        ]);
    }

    /**
     * El chat del cliente de un pedido, a un toque del pedido.
     *
     * Página propia y no un panel cargado por axios: el inbox es un componente
     * Vue con sus propios scripts, y traerlo como HTML suelto no lo arranca.
     */
    public function whatsapp(string $id)
    {
        $lead = LeadProxy::modelClass()::with('person')->findOrFail($id);

        abort_if(
            ! $this->alcance->puedeVerCiudad((int) $lead->lead_pipeline_id),
            403,
            'Ese pedido no está en tu alcance.',
        );

        abort_if(! $lead->person, 404, 'Ese pedido no tiene un contacto asociado.');

        return view('distribuidor::whatsapp', [
            'lead'   => $lead,
            'person' => $lead->person,
        ]);
    }

    /**
     * Los últimos clientes atendidos en este lugar.
     *
     * Hoy la única puerta de entrada al pedido es escribir el teléfono completo,
     * y es el primer paso de TODOS los pedidos. Con los contactos que ya hay
     * cargados, una fila de tarjetas grandes saltea el teclado en la mayoría de
     * los casos — que es la diferencia entre tipear 10 dígitos con una mano
     * parado en la calle y tocar una vez.
     *
     * Se listan por último pedido y no alfabéticamente: a quien le vendiste ayer
     * es mucho más probable que sea el de hoy.
     */
    public function recientes(Request $request): JsonResponse
    {
        $data = $request->validate(['ciudad' => 'nullable|integer']);

        $query = LeadProxy::modelClass()::query()
            ->whereNotNull('person_id')
            ->when(
                ! empty($data['ciudad']),
                fn ($q) => $q->where('lead_pipeline_id', $data['ciudad']),
                fn ($q) => ($p = $this->alcance->ciudadesPermitidas()) !== null
                    ? $q->whereIn('lead_pipeline_id', $p)
                    : $q,
            );

        if (! empty($data['ciudad'])) {
            abort_if(
                ! $this->alcance->puedeVerCiudad((int) $data['ciudad']),
                403,
                'Esa ciudad no está en tu alcance.',
            );
        }

        /*
         * Un cliente aparece UNA vez, con su pedido más reciente. Sin el
         * groupBy, alguien que compró cinco veces llenaría la fila entero y
         * taparía a los demás.
         */
        $ultimos = $query->selectRaw('person_id, max(id) as ultimo_lead')
            ->groupBy('person_id')
            ->orderByDesc('ultimo_lead')
            ->limit(8)
            ->get();

        return response()->json([
            'recientes' => $ultimos->map(function ($fila) {
                $persona = PersonProxy::modelClass()::find($fila->person_id);

                if (! $persona) {
                    return null;
                }

                // Sin teléfono la tarjeta no sirve: todo el Punto identifica al
                // cliente por su número, y una tarjeta que no se puede usar sólo
                // ocupa un lugar en una fila de ocho.
                if (! $this->telefonoDe($persona)) {
                    return null;
                }

                return array_merge($this->persona($persona), [
                    'ultimo_pedido_id' => (int) $fila->ultimo_lead,
                ]);
            })->filter()->values()->all(),
        ]);
    }

    /**
     * Las líneas del último pedido de un cliente, para repetirlo.
     *
     * En este rubro el mismo cliente pide lo mismo cada semana: repetir convierte
     * un pedido de seis toques en dos.
     *
     * Los precios se devuelven los del CATÁLOGO ACTUAL de la sucursal, no los que
     * se cobraron la vez pasada: si la lista subió, repetir al precio viejo
     * vendería a pérdida sin que nadie lo note. Lo que se repite es QUÉ pidió, no
     * a cuánto.
     */
    public function repetir(Request $request, string $personId): JsonResponse
    {
        $data = $request->validate(['sucursal' => 'required|integer']);

        $permitidas = array_column($this->alcance->sucursales(), 'id');

        abort_if(! in_array((int) $data['sucursal'], $permitidas, true), 403, 'Esa sucursal no está en tu alcance.');

        $ultimo = LeadProxy::modelClass()::with('products')
            ->where('person_id', $personId)
            ->orderByDesc('id')
            ->first();

        if (! $ultimo || $ultimo->products->isEmpty()) {
            return response()->json([
                'lineas' => [],
                'motivo' => 'Ese cliente no tiene un pedido anterior con productos.',
            ]);
        }

        // Sólo lo que la sucursal vende hoy: un producto discontinuado o de otra
        // ciudad no puede volver a entrar por la puerta de atrás.
        $catalogo = collect($this->catalogo->productosDe((int) $data['sucursal']))->keyBy('id');

        $lineas = $ultimo->products
            ->filter(fn ($p) => $catalogo->has($p->product_id))
            ->map(fn ($p) => [
                'product_id' => (int) $p->product_id,
                'name'       => $catalogo[$p->product_id]['name'],
                'quantity'   => max(1, (int) $p->quantity),
                'price'      => $catalogo[$p->product_id]['price'],
            ])
            ->values()
            ->all();

        return response()->json([
            'lineas' => $lineas,
            'motivo' => $lineas ? null : 'Lo que pidió la vez pasada no está disponible en esta sucursal.',
        ]);
    }

    /**
     * La caja del día: lo que este distribuidor cobró hoy.
     *
     * Registraban cobros sin poder ver el total propio, y es EL número que
     * necesita quien cierra la jornada entregando efectivo. Cortado por forma de
     * pago porque lo que se rinde en mano es sólo la parte en efectivo.
     */
    public function caja(): JsonResponse
    {
        $userId = auth()->guard('user')->id();

        $pagos = PagoProxy::modelClass()::query()
            ->where('user_id', $userId)
            ->whereDate('created_at', now()->toDateString())
            ->get();

        $porForma = collect(config('distribuidor.formas_de_pago'))
            ->map(fn ($label, $key) => [
                'key'    => $key,
                'label'  => $label,
                'total'  => round((float) $pagos->where('method', $key)->sum('amount'), 2),
                'cobros' => $pagos->where('method', $key)->count(),
            ])
            ->values()
            ->all();

        return response()->json([
            'total'           => round((float) $pagos->sum('amount'), 2),
            'cobros'          => $pagos->count(),
            'por_forma'       => $porForma,
            'pedidos_del_dia' => LeadProxy::modelClass()::where('user_id', $userId)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
        ]);
    }

    /**
     * Un pedido tal como lo muestra el Punto: con su saldo ya resuelto.
     */
    protected function pedido(int $id): array
    {
        $lead = LeadProxy::modelClass()::with(['products', 'stage', 'person'])->findOrFail($id);

        $total = (float) $lead->lead_value;
        $cobrado = $this->pagos->cobrado($id);

        return [
            'id'         => (int) $lead->id,
            'title'      => $lead->title,
            'cliente'    => $lead->person?->name,
            'telefono'   => $this->telefonoDe($lead->person),
            'ciudad_id'  => (int) $lead->lead_pipeline_id,
            'etapa'      => optional($lead->stage)->name,
            'etapa_code' => optional($lead->stage)->code,
            'total'      => $total,
            'cobrado'    => $cobrado,
            // El saldo se calcula, nunca se guarda: así no puede quedar
            // desincronizado con los cobros.
            'saldo'         => round($total - $cobrado, 2),
            'ubicacion'     => $this->ubicacion->dePedido($id),
            'entregado_at'  => optional($this->entregaDe($id))->entregado_at?->format('d/m/Y H:i'),
            'entregado_por' => optional(optional($this->entregaDe($id))->user)->name,
            'entrega_foto'  => ($f = optional($this->entregaDe($id))->foto_path)
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($f)
                : null,
            'productos'     => $lead->products->map(fn ($p) => [
                'name'     => $p->name,
                'quantity' => (float) $p->quantity,
                'price'    => (float) $p->price,
            ])->values()->all(),
        ];
    }

    /**
     * La última entrega del pedido. Memoizada: `pedido()` la pide dos veces (la
     * fecha y quién), y la lista del Punto llama a `pedido()` por cada fila.
     *
     * @var array<int, mixed>
     */
    protected array $entregas = [];

    protected function entregaDe(int $leadId)
    {
        return $this->entregas[$leadId] ??= EntregaProxy::modelClass()::with('user')
            ->where('lead_id', $leadId)
            ->latest('entregado_at')
            ->first();
    }

    protected function persona($persona): array
    {
        return [
            'id'       => (int) $persona->id,
            'name'     => $persona->name,
            'telefono' => $this->telefonoDe($persona),
        ];
    }

    protected function telefonoDe($persona): ?string
    {
        if (! $persona) {
            return null;
        }

        $numeros = (array) $persona->contact_numbers;

        return $numeros ? ($numeros[0]['value'] ?? null) : null;
    }
}
