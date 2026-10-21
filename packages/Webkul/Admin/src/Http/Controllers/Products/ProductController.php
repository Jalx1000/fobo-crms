<?php

namespace Webkul\Admin\Http\Controllers\Products;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\DataGrids\Product\ProductDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\AttributeForm;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Resources\ProductResource;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductPriceRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ProductVarianteRepository;
use Webkul\Product\Support\Caracteristicas;
use Webkul\Product\Support\ListaPrecios;
use Webkul\Product\Support\Variantes;

class ProductController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected ProductMediaRepository $productMediaRepository,
        protected ProductPriceRepository $productPriceRepository,
        protected ProductVarianteRepository $productVarianteRepository,
    ) {
        request()->request->add(['entity_type' => 'products']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if (request()->ajax()) {
            return datagrid(ProductDataGrid::class)->process();
        }

        return view('admin::products.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin::products.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(AttributeForm $request)
    {
        $data = $request->all();

        if (empty($data['sku'])) {
            $data['sku'] = uniqid();
        }

        // El formulario manda dos arrays paralelos (clave[]/valor[]) porque el
        // asesor puede agregar filas con claves que no están en la config.
        $lista = $this->listaDePrecios($request);

        $variantes = $this->variantes($request, $lista[0] ?? $request->input('ejes_precio', []));

        unset($data['precios'], $data['precios_enviados'], $data['ejes_precio'], $data['caracteristicas_enviadas'],
            $data['variantes'], $data['variantes_enviadas'], $data['ejes_identidad']);

        if ($variantes) {
            $data['ejes_identidad'] = $variantes[0];
        }

        $this->caracteristicas($request, $data);

        $this->entrega($request, $data);

        $responsables = $this->responsables($request, $data);

        if ($lista) {
            $data['ejes_precio'] = $lista[0];
        }

        Event::dispatch('product.create.before');

        $product = $this->productRepository->create($data);

        Event::dispatch('product.create.after', $product);

        if ($lista) {
            $this->productPriceRepository->reemplazar($product->id, $lista[1]);
        }

        if ($variantes) {
            $this->productVarianteRepository->reemplazar($product->id, $variantes[1]);
        }

        if ($responsables !== null) {
            $this->guardarResponsables($product, $responsables);
        }

        $this->guardarImagenesDelAlta($request, $product);

        session()->flash('success', trans('admin::app.products.index.create-success'));

        return redirect()->route('admin.products.index');
    }

    /**
     * Show the form for viewing the specified resource.
     */
    public function view(int $id): View
    {
        $product = $this->productRepository->findOrFail($id);

        return view('admin::products.view', compact('product'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View|JsonResponse
    {
        $product = $this->productRepository->findOrFail($id);

        $inventories = $product->inventories()
            ->with('location')
            ->get()
            ->map(function ($inventory) {
                return [
                    'id'                    => $inventory->id,
                    'name'                  => $inventory->location->name,
                    'warehouse_id'          => $inventory->warehouse_id,
                    'warehouse_location_id' => $inventory->warehouse_location_id,
                    'in_stock'              => $inventory->in_stock,
                    'allocated'             => $inventory->allocated,
                ];
            });

        return view('admin::products.edit', compact('product', 'inventories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AttributeForm $request, int $id)
    {
        Event::dispatch('product.update.before', $id);

        $data = $request->all();

        $lista = $this->listaDePrecios($request);

        $variantes = $this->variantes($request, $lista[0] ?? $request->input('ejes_precio', []));

        unset($data['precios'], $data['precios_enviados'], $data['ejes_precio'], $data['caracteristicas_enviadas'],
            $data['variantes'], $data['variantes_enviadas'], $data['ejes_identidad']);

        if ($variantes) {
            $data['ejes_identidad'] = $variantes[0];
        }

        $this->caracteristicas($request, $data);

        $this->entrega($request, $data);

        $responsables = $this->responsables($request, $data);

        if ($lista) {
            $data['ejes_precio'] = $lista[0];
        }

        $product = $this->productRepository->update($data, $id);

        Event::dispatch('product.update.after', $product);

        if ($lista) {
            $this->productPriceRepository->reemplazar($product->id, $lista[1]);
        }

        if ($variantes) {
            $this->productVarianteRepository->reemplazar($product->id, $variantes[1]);
        }

        if ($responsables !== null) {
            $this->guardarResponsables($product, $responsables);
        }

        if (request()->ajax()) {
            return response()->json([
                'message' => trans('admin::app.products.index.update-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.products.index.update-success'));

        return redirect()->route('admin.products.index');
    }

    /**
     * Imágenes soltadas en el alta.
     *
     * Viajan con el formulario porque en el alta todavía no hay producto al que
     * subirlas por AJAX. Se validan acá y no en un FormRequest para que un
     * archivo rechazado no tire abajo la creación del producto: es peor perder
     * la carga entera por una imagen de más que guardar el producto sin ella.
     */
    /**
     * Ejes de identidad y filas de variantes del formulario, o null si no vino
     * la tabla — mismo motivo que la lista de precios: un guardado parcial no
     * puede borrarla.
     *
     * @return array{0: ?array, 1: array}|null
     */
    protected function variantes(AttributeForm $request, mixed $ejesPrecio): ?array
    {
        if (! $request->boolean('variantes_enviadas')) {
            return null;
        }

        $ejesPrecio = array_values(array_filter((array) $ejesPrecio, fn ($e) => trim((string) $e) !== ''));

        $ejes = Variantes::normalizarEjes($request->input('ejes_identidad'), $ejesPrecio);

        $filas = $ejes ? Variantes::normalizarFilas($request->input('variantes'), $ejes) : [];

        return [$filas ? $ejes : null, $filas];
    }

    /**
     * La matriz de responsables del formulario, o null si no vino.
     *
     * Devuelve `[ciudad => [user_id, ...]]` con las marcadas y descarta las
     * vacías: una ciudad sin nadie significa que nadie atiende ese producto
     * ahí, no que haya que guardar una fila con usuario nulo. Varias personas
     * en la misma ciudad se turnan (ver `Responsables`).
     *
     * La planta llega como ubicación (`warehouse_location_id`) y el almacén se
     * toma de ella: hay uno solo, Santa Cruz, con una ubicación por planta. Las
     * dos son columnas del producto y se limpian a null con el select vacío.
     */
    protected function responsables(AttributeForm $request, array &$data): ?array
    {
        if (! $request->boolean('sucursal_enviada')) {
            unset($data['warehouse_id'], $data['warehouse_location_id'], $data['responsables'], $data['sucursal_enviada']);

            return null;
        }

        $ubicacion = $request->input('warehouse_location_id')
            ? \Webkul\Warehouse\Models\LocationProxy::modelClass()::find($request->input('warehouse_location_id'))
            : null;

        $data['warehouse_location_id'] = $ubicacion?->id;
        $data['warehouse_id'] = $ubicacion?->warehouse_id;

        $matriz = [];

        foreach ((array) $request->input('responsables', []) as $ciudad => $userIds) {
            $ciudad = trim((string) $ciudad);

            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $userIds))));

            if ($ciudad !== '' && $ids) {
                $matriz[$ciudad] = $ids;
            }
        }

        unset($data['responsables'], $data['sucursal_enviada']);

        return $matriz;
    }

    /**
     * Deja la matriz del producto exactamente como vino del formulario.
     *
     * Se borran las casillas que ya no están y se agregan las nuevas; las que
     * siguen se dejan como están para no perder `ultimo_asignado_at`: si se
     * reinsertara todo, cada guardado reiniciaría el turno del round robin.
     */
    protected function guardarResponsables($product, array $matriz): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($product, $matriz) {
            $modelo = \Webkul\Product\Models\ProductoResponsableProxy::modelClass();

            $actuales = $modelo::where('product_id', $product->id)->get();

            $deseadas = [];

            foreach ($matriz as $ciudad => $userIds) {
                foreach ($userIds as $userId) {
                    $deseadas[$ciudad.'|'.$userId] = ['ciudad' => $ciudad, 'user_id' => $userId];
                }
            }

            foreach ($actuales as $fila) {
                if (! isset($deseadas[$fila->ciudad.'|'.$fila->user_id])) {
                    $fila->delete();
                }

                unset($deseadas[$fila->ciudad.'|'.$fila->user_id]);
            }

            foreach ($deseadas as $nueva) {
                $modelo::create(['product_id' => $product->id] + $nueva);
            }
        });
    }

    /**
     * Condiciones de entrega del formulario, o nada si no vinieron.
     *
     * `entrega_domicilio_desde` vacío es null y no 0: null significa «nunca va
     * a domicilio» (bolsas, tapas); 0 significaría «siempre», que es lo
     * contrario.
     */
    protected function entrega(AttributeForm $request, array &$data): void
    {
        if (! $request->has('tiempo_entrega') && ! $request->has('lugar_entrega')) {
            unset($data['tiempo_entrega'], $data['lugar_entrega'], $data['entrega_domicilio_desde']);

            return;
        }

        $desde = trim((string) $request->input('entrega_domicilio_desde', ''));

        $data['entrega_domicilio_desde'] = $desde === '' ? null : max(1, (int) $desde);
        $data['tiempo_entrega'] = trim((string) $request->input('tiempo_entrega')) ?: 'Inmediata';
        $data['lugar_entrega'] = trim((string) $request->input('lugar_entrega')) ?: 'Puesto almacén cliente';
    }

    /**
     * Normaliza las características, pero solo si el formulario las trajo.
     *
     * Mismo motivo que en la lista de precios: el editor inline de atributos de
     * la vista del producto postea a esta misma ruta sin el campo, y tratarlo
     * como «vacías» borraba todo lo cargado sin decir nada.
     */
    protected function caracteristicas(AttributeForm $request, array &$data): void
    {
        if (! $request->boolean('caracteristicas_enviadas')) {
            unset($data['caracteristicas']);

            return;
        }

        $data['caracteristicas'] = Caracteristicas::normalizar($request->input('caracteristicas'));
    }

    /**
     * Los ejes y las filas de la lista de precios que vinieron del formulario.
     *
     * Devuelve las dos cosas juntas porque no tienen sentido por separado: las
     * filas se arman contra los ejes declarados, y guardar unos sin las otras
     * deja un producto que dice cotizarse por Tamaño y no tiene ningún tamaño.
     *
     * `precios` se saca de `$data` antes de que llegue al repositorio: no es una
     * columna de `products` ni un atributo personalizado, va a su propia tabla.
     *
     * Devuelve `null` cuando el formulario NO trae la tabla. No es lo mismo que
     * traerla vacía: el editor inline de atributos de la vista del producto
     * postea a esta misma ruta con un puñado de campos, y sin esta distinción
     * cambiar un atributo ahí borraría la lista de precios entera.
     */
    protected function listaDePrecios(AttributeForm $request): ?array
    {
        if (! $request->boolean('precios_enviados')) {
            return null;
        }

        $ejes = ListaPrecios::normalizarEjes($request->input('ejes_precio'));

        $filas = $ejes
            ? ListaPrecios::normalizarFilas($request->input('precios'), $ejes)
            : [];

        // Sin filas cargadas no hay lista, y entonces tampoco hay ejes: dejar
        // los ejes solos haría que la ficha anuncie un precio variable que no
        // existe todavia.
        return [$filas ? $ejes : null, $filas];
    }

    protected function guardarImagenesDelAlta(AttributeForm $request, $product): void
    {
        $imagenes = $request->file('imagenes', []);

        if (empty($imagenes)) {
            return;
        }

        $validador = Validator::make(
            ['imagenes' => $imagenes],
            ['imagenes' => 'array|max:20', 'imagenes.*' => 'file|mimes:jpg,jpeg,png,webp|max:5120'],
        );

        if ($validador->fails()) {
            session()->flash('warning', trans('admin::app.products.medias.at-create-rejected'));

            return;
        }

        $orden = 0;

        foreach ($imagenes as $imagen) {
            $this->productMediaRepository->create([
                'product_id' => $product->id,
                'tipo'       => 'imagen',
                'path'       => $imagen->store("productos/{$product->id}", 'local'),
                'orden'      => ++$orden,
                // La primera es la portada: si nadie elige una, la ficha igual
                // tiene con qué mostrarse.
                'es_portada' => $orden === 1,
            ]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function storeInventories(int $id, ?int $warehouseId = null): JsonResponse
    {
        $this->validate(request(), [
            'inventories'                         => 'array',
            'inventories.*.warehouse_location_id' => 'required',
            'inventories.*.warehouse_id'          => 'required',
            'inventories.*.in_stock'              => 'required|integer|min:0',
            'inventories.*.allocated'             => 'required|integer|min:0',
        ]);

        $product = $this->productRepository->findOrFail($id);

        Event::dispatch('product.update.before', $id);

        $this->productRepository->saveInventories(request()->all(), $id, $warehouseId);

        Event::dispatch('product.update.after', $product);

        return new JsonResponse([
            'message' => trans('admin::app.products.index.update-success'),
        ], 200);
    }

    /**
     * Search product results
     */
    public function search(): JsonResource
    {
        $limit = request()->get('limit') ?? 200;

        /**
         * The lookup component sends the typed text as the "query" parameter,
         * but RequestCriteria only filters using the "search" parameter, so the
         * server never narrowed the results. We apply the filter explicitly here
         * to actually search by name/sku instead of only returning the most
         * recent products.
         */
        $searchTerm = request()->get('query');

        $repository = $this->productRepository->with('variantes')->pushCriteria(app(RequestCriteria::class));

        if (! empty($searchTerm)) {
            $repository = $repository->scopeQuery(function ($query) use ($searchTerm) {
                return $query->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'like', '%'.$searchTerm.'%')
                        ->orWhere('sku', 'like', '%'.$searchTerm.'%');
                });
            });
        }

        $products = $repository
            ->orderBy('created_at', 'desc')
            ->take((int) $limit)
            ->get();

        return ProductResource::collection($products);
    }

    /**
     * El precio de lista para una fila de la cotización.
     *
     * El formulario manda la variante elegida, la cantidad y los ejes que el
     * asesor completó aparte («Factura» en Hules, «Canal» y «Zona» en Bolsas);
     * la fila se resuelve con ListaPrecios, igual que para el agente, para que
     * el asesor y el agente coticen el mismo número.
     *
     * Estados: `fijo` (el producto no tiene lista: su precio), `resuelto`,
     * `ambiguo` (faltan ejes: van con sus opciones), `sin_datos` (la
     * combinación no está o no tiene precio: se escribe a mano).
     */
    public function precio(int $id): JsonResponse
    {
        $datos = request()->validate([
            'variante_id' => 'nullable|integer',
            'cantidad'    => 'nullable|numeric|min:0',
            'criterios'   => 'nullable|array',
        ]);

        $product = $this->productRepository->with(['precios', 'variantes'])->findOrFail($id);

        if ($product->precios->isEmpty()) {
            return new JsonResponse(['estado' => 'fijo', 'precio' => (float) $product->price]);
        }

        $variante = $product->variantes->firstWhere('id', $datos['variante_id'] ?? null);

        $criterios = array_filter(
            array_merge((array) ($variante?->valores ?? []), (array) ($datos['criterios'] ?? [])),
            fn ($valor) => is_scalar($valor) && trim((string) $valor) !== ''
        );

        $candidatas = ListaPrecios::filtrar(
            $product->precios,
            ListaPrecios::conCantidad($criterios, $datos['cantidad'] ?? null)
        );

        if ($candidatas->count() === 1) {
            $fila = $candidatas->first();

            return new JsonResponse($fila->cotiza()
                ? ['estado' => 'resuelto', 'precio' => (float) $fila->precio, 'unidad' => $fila->unidad, 'nota' => $fila->nota]
                : ['estado' => 'sin_datos', 'nota' => $fila->nota]);
        }

        if ($candidatas->isEmpty()) {
            return new JsonResponse(['estado' => 'sin_datos', 'nota' => null]);
        }

        // Los ejes en los que las candidatas todavía difieren, con sus opciones.
        // La cantidad no: se resuelve sola con la que ya tiene la fila. Los de
        // identidad tampoco, si hay variantes: los elige el selector de variante.
        $deLaVariante = $product->variantes->isNotEmpty() ? (array) ($product->ejes_identidad ?? []) : [];

        $faltan = collect((array) ($product->ejes_precio ?? []))
            ->reject(fn ($eje) => ListaPrecios::comparable((string) $eje) === 'cantidad' || in_array($eje, $deLaVariante, true))
            ->map(fn ($eje) => [
                'eje'      => $eje,
                'opciones' => $candidatas->map(fn ($fila) => $fila->valores[$eje] ?? null)->filter()->unique()->values()->all(),
            ])
            ->filter(fn ($eje) => count($eje['opciones']) > 1)
            ->values();

        return new JsonResponse(['estado' => 'ambiguo', 'faltan' => $faltan]);
    }

    /**
     * Returns product inventories grouped by warehouse.
     */
    public function warehouses(int $id): JsonResponse
    {
        $warehouses = $this->productRepository->getInventoriesGroupedByWarehouse($id);

        return response()->json(array_values($warehouses));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $product = $this->productRepository->findOrFail($id);

        try {
            Event::dispatch('settings.products.delete.before', $id);

            $product->delete($id);

            Event::dispatch('settings.products.delete.after', $id);

            return new JsonResponse([
                'message' => trans('admin::app.products.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'message' => trans('admin::app.products.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Mass Delete the specified resources.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $indices = $massDestroyRequest->input('indices');

        foreach ($indices as $index) {
            Event::dispatch('product.delete.before', $index);

            $this->productRepository->delete($index);

            Event::dispatch('product.delete.after', $index);
        }

        return new JsonResponse([
            'message' => trans('admin::app.products.index.delete-success'),
        ]);
    }
}
