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
use Webkul\Distribuidor\Services\Bidones;
use Webkul\Distribuidor\Services\Catalogo;
use Webkul\Distribuidor\Services\PuntoPedido;
use Webkul\Distribuidor\Services\RegistroDeEdicion;
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
        protected Bidones $bidones,
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

            /*
             * El centro del mapa: el medio de la zona de reparto configurada, no una
             * coordenada escrita a mano. Así una instalación en otra provincia abre el
             * mapa donde trabaja con sólo cambiar la zona, en vez de mostrar el mapa
             * de otra ciudad hasta que alguien se acuerde de tocar el código.
             */
            'mapaCentro' => [
                'lat'  => (float) ((config('distribuidor.zona.lat_min') + config('distribuidor.zona.lat_max')) / 2),
                'lng'  => (float) ((config('distribuidor.zona.lng_min') + config('distribuidor.zona.lng_max')) / 2),
                'zoom' => 11,
            ],
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

            /*
             * El domicilio y los bidones se cargan JUNTO con el contacto y no en una
             * pantalla aparte: son los dos datos que el distribuidor tiene a mano
             * justo cuando está conociendo al cliente, y pedirlos después significa
             * que no se cargan nunca.
             *
             * Los dos opcionales: un contacto se puede crear por teléfono sin saber
             * todavía dónde vive ni cuántos envases tiene.
             */
            'lat'     => 'nullable|numeric|between:-90,90',
            'lng'     => 'nullable|numeric|between:-180,180',
            // lng es obligatoria si vino lat: media coordenada no ubica nada.
            'bidones' => 'nullable|integer|min:0',
        ]);

        if (isset($data['lat']) !== isset($data['lng'])) {
            return response()->json([
                'message' => 'La ubicación necesita latitud y longitud.',
            ], 422);
        }

        $r = $this->pedidos->contacto($data['telefono'], $data['nombre']);
        $personId = (int) $r['persona']->id;

        if (isset($data['lat'], $data['lng'])) {
            $this->ubicacion->guardarDelMapa($personId, (float) $data['lat'], (float) $data['lng']);
        }

        /*
         * Los bidones iniciales entran como "entregados" porque el saldo se calcula
         * sumando movimientos y es la única forma de que dé positivo. No es que se
         * los hayamos entregado hoy: es el saldo de arranque, los envases que el
         * cliente ya tenía antes de que existiera el control.
         */
        if (! empty($data['bidones'])) {
            $this->bidones->registrar($personId, null, (int) $data['bidones'], 0);
        }

        return response()->json([
            // Se devuelve la persona YA con su saldo y su domicilio, para que la
            // pantalla no tenga que volver a pedirlos.
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
             * Opcional. Sigue siendo la mejor prueba de que el pedido llegó
             * —cuando el cliente dice que no recibió nada, la fecha sola no
             * alcanza—, pero exigirla bloqueaba la entrega en los casos en que no
             * se puede sacar: sin batería, sin luz, el cliente que no quiere que
             * le fotografíen la puerta. Un pedido entregado que el sistema no deja
             * cerrar es peor que un pedido cerrado sin foto.
             */
            'foto'  => 'nullable|image|max:8192',

            /*
             * Los bidones de la visita. Opcionales: si el cliente compra agua en
             * botella no hay envase que mover, y obligar a escribir dos ceros sería
             * trabajo por nada.
             *
             * Sin máximo arbitrario: un pedido de un comercio puede ser de 40
             * bidones, y un techo inventado lo bloquearía sin motivo.
             */
            'bidones_entregados' => 'nullable|integer|min:0',
            'bidones_retirados'  => 'nullable|integer|min:0',
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
                // Null-safe: la foto es opcional y sin el ?-> esto reventaba.
                'foto_path'    => $request->file('foto')?->store('distribuidor/entregas', 'public'),
            ]);

            /*
             * Los bidones se cuentan contra el CLIENTE y no contra el pedido: el
             * saldo que importa es "cuántos tiene en su casa", y eso atraviesa
             * todos sus pedidos. El lead queda igual en el movimiento, para poder
             * reconstruir en qué visita pasó.
             */
            if ($lead->person_id) {
                $this->bidones->registrar(
                    (int) $lead->person_id,
                    (int) $id,
                    (int) ($data['bidones_entregados'] ?? 0),
                    (int) ($data['bidones_retirados'] ?? 0),
                );
            }
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
            /*
             * Los bidones se cuadran igual que la plata: lo que dejó salió de la
             * camioneta y lo que retiró tiene que volver a ella. `en_la_calle` es
             * la diferencia, que es el número que se mira al cerrar el día.
             */
            'bidones'   => $this->bidones->delDia($userId),
            // A quién hay que ir a buscarle los envases, dentro de su alcance.
            'a_recuperar' => $this->bidones->pendientesDeDevolucion(
                $this->alcance->ciudadesPermitidas(),
                15,
            ),
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
            // Para marcar el domicilio con el GPS desde el diálogo de entrega: el
            // domicilio es del cliente, no del pedido.
            'person_id'  => $lead->person_id ? (int) $lead->person_id : null,
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
            // Cuántos bidones tiene el cliente, para decidir cuántos retirar.
            'bidones'       => $this->bidones->saldoDe($lead->person_id ? (int) $lead->person_id : null),
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

    /**
     * Guarda el domicilio del cliente con el GPS del celular.
     *
     * Se toma en la puerta al entregar, que es el único momento en que el teléfono
     * está realmente en el domicilio. Pisa el pin de WhatsApp a propósito: ese lo
     * manda el cliente desde donde está parado, que puede ser el trabajo.
     */
    public function guardarUbicacionCliente(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            // Los rangos son los del mundo: no se recorta por geografía. El detalle
            // de por qué está en Ubicacion::guardarGps().
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',

            /*
             * El error en metros que informa el navegador. Opcional porque no todos
             * lo mandan, y es mejor una ubicación sin medida de calidad que ninguna.
             */
            'precision' => 'nullable|numeric|min:0',

            /*
             * De dónde salió el punto. No es decorativo: al GPS se le mide la
             * precisión —para distinguir la puerta del cliente de la antena— y a un
             * punto puesto a mano sobre el mapa no, porque la calidad ya la juzgó la
             * persona que lo eligió mirando el domicilio.
             *
             * Default 'gps' para no romper al botón que ya existía.
             */
            'origen' => 'nullable|string|in:gps,mapa',
        ]);

        $persona = PersonProxy::modelClass()::findOrFail($id);

        $precision = isset($data['precision']) ? (float) $data['precision'] : null;
        $delMapa = ($data['origen'] ?? 'gps') === 'mapa';

        $guardada = $delMapa
            ? $this->ubicacion->guardarDelMapa((int) $persona->id, (float) $data['lat'], (float) $data['lng'])
            : $this->ubicacion->guardarGps(
                (int) $persona->id,
                (float) $data['lat'],
                (float) $data['lng'],
                null,
                $precision,
            );

        if (! $guardada) {
            /*
             * 422 y no 500: el pedido está bien formado, lo que está mal es la
             * calidad del punto. El mensaje dice el número medido y el aceptado,
             * porque "probá de nuevo" sin decir qué estuvo mal manda a repetir la
             * misma acción esperando otro resultado.
             */
            $maxima = (int) config('distribuidor.zona.precision_maxima');

            return response()->json([
                'message' => $delMapa
                    ? 'No se pudo guardar el domicilio.'
                    : ($precision !== null
                    ? sprintf(
                        'La ubicación llegó con %d m de error y se aceptan hasta %d m. Es la antena y no el GPS: salí a cielo abierto y probá de nuevo.',
                        (int) round($precision),
                        $maxima,
                    )
                    : 'No se pudo guardar la ubicación.'),
            ], 422);
        }

        return response()->json(['ubicacion' => $this->ubicacion->deCliente((int) $persona->id)]);
    }

    /**
     * Cambia los productos de un pedido, dejando registro de la edición.
     *
     * Editable incluso cobrado o entregado, que es lo que pidió el negocio: el agente de
     * WhatsApp arma pedidos solo, y la corrección real aparece cuando el distribuidor
     * llega a la puerta y el cliente le dice que pidió otra cosa. Negarlo ahí obliga a
     * cancelar y recargar, que pierde el historial y el cobro.
     */
    public function editarProductos(Request $request, string $id, RegistroDeEdicion $registro): JsonResponse
    {
        $data = $request->validate([
            // La misma forma que al crear: un pedido editado no es un pedido distinto.
            'productos'              => 'required|array|min:1',
            'productos.*.product_id' => 'required|integer|exists:products,id',
            'productos.*.quantity'   => 'required|integer|min:1',
            'productos.*.price'      => 'required|numeric|min:0',
        ]);

        $lead = LeadProxy::modelClass()::findOrFail($id);

        abort_if(
            ! $this->alcance->puedeVerCiudad((int) $lead->lead_pipeline_id),
            403,
            'Ese pedido no está en tu alcance.',
        );

        /*
         * Un pedido cancelado no se edita: no hay nada que entregar, y editarlo lo
         * dejaría con líneas nuevas y sin destino. Si hay que vender de nuevo, es un
         * pedido nuevo.
         */
        if (optional($lead->stage)->code === config('distribuidor.etapas.cancelado')) {
            return response()->json([
                'message' => 'Ese pedido está cancelado. Si hay que venderle de nuevo, cargá un pedido nuevo.',
            ], 422);
        }

        $cobrado = $this->pagos->cobrado((int) $id);

        $cambio = $this->pedidos->editar((int) $id, $data['productos']);

        /*
         * El registro se guarda SIEMPRE y es la condición que puso el negocio para
         * permitir editar algo ya cobrado. Va después de aplicar el cambio porque
         * necesita el estado final.
         */
        $registro->guardar((int) $id, $cambio['antes'], $cambio['despues'], $cobrado);

        return response()->json(['pedido' => $this->pedido((int) $id)]);
    }

    /**
     * El apartado de bidones: quién debe envases y cuántos hay en la calle.
     *
     * Aparte de Caja y no dentro, que es donde estaba: Caja se mira una vez al cerrar
     * el día, y los envases a recuperar se miran al ARMAR el recorrido. Enterrado ahí
     * abajo, nadie los veía a tiempo para ir a buscarlos.
     */
    public function bidones(): JsonResponse
    {
        $ciudades = $this->alcance->ciudadesPermitidas();
        $clientes = $this->bidones->pendientesDeDevolucion($ciudades, 100);

        return response()->json([
            'clientes' => $clientes,
            /*
             * El total sale de sumar la lista y no de otra consulta: así el número de
             * arriba y las filas de abajo no pueden contradecirse, que es el problema
             * clásico de un resumen calculado por separado.
             */
            'en_la_calle' => array_sum(array_column($clientes, 'saldo')),
            'del_dia'     => $this->bidones->delDia(),
        ]);
    }

    /**
     * Registra bidones sin pedido: pasar a juntar vacíos es una visita real.
     */
    public function registrarBidones(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'entregados' => 'nullable|integer|min:0',
            'retirados'  => 'nullable|integer|min:0',
        ]);

        $persona = PersonProxy::modelClass()::findOrFail($id);

        $this->bidones->registrar(
            (int) $persona->id,
            null,
            (int) ($data['entregados'] ?? 0),
            (int) ($data['retirados'] ?? 0),
        );

        return response()->json(['bidones' => $this->bidones->saldoDe((int) $persona->id)]);
    }

    protected function persona($persona): array
    {
        return [
            'id'       => (int) $persona->id,
            'name'     => $persona->name,
            'telefono' => $this->telefonoDe($persona),
            // Cuántos bidones tiene en su casa. Se muestra al encontrarlo, que es
            // el momento en que el distribuidor decide cuántos llevar a la puerta.
            'bidones'   => $this->bidones->saldoDe((int) $persona->id),
            'ubicacion' => $this->ubicacion->deCliente((int) $persona->id),
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
