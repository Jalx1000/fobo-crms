<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeRepository;

/**
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="token",
 *     description="Sanctum personal access token. Obtain it via the admin login."
 * )
 */
class ProductoController extends Controller
{
    public function __construct(
        protected AttributeRepository $attributeRepository
    ) {}

    /**
     * @OA\Get(
     *     path="/api/productos/por-ciudad-sucursal",
     *     summary="Obtiene productos filtrados por ciudad/sucursal",
     *     description="Devuelve los productos que coinciden con el valor de `ciudad_producto_sucursal` indicado. Siempre se incluyen también los productos con valor 10 en ese atributo, sin importar el filtro aplicado.",
     *     operationId="getProductosByCiudadSucursal",
     *     tags={"Productos"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="ciudad_producto_sucursal",
     *         in="query",
     *         required=true,
     *         description="ID del valor del atributo ciudad_producto_sucursal por el que filtrar",
     *
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Número de página",
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Cantidad de resultados por página (máximo 100)",
     *
     *         @OA\Schema(type="integer", example=15)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Lista de productos",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="sku", type="string", example="PROD-001"),
     *                     @OA\Property(property="name", type="string", example="Producto Ejemplo"),
     *                     @OA\Property(property="price", type="number", format="float", example=99.99),
     *                     @OA\Property(property="ciudad_producto_sucursal", type="integer", example=5),
     *                     @OA\Property(property="siempre_incluido", type="boolean", example=false,
     *                         description="true si el producto se incluyó porque su valor es 10"
     *                     )
     *                 )
     *             ),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="per_page", type="integer", example=15),
     *                 @OA\Property(property="total", type="integer", example=42),
     *                 @OA\Property(property="last_page", type="integer", example=3)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Parámetro requerido faltante o inválido",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="El parámetro ciudad_producto_sucursal es requerido y debe ser un entero positivo")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Atributo ciudad_producto_sucursal no encontrado",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="El atributo ciudad_producto_sucursal no está configurado en el sistema")
     *         )
     *     )
     * )
     */
    public function porCiudadSucursal(Request $request): JsonResponse
    {
        $rawValue = $request->query('ciudad_producto_sucursal');

        if ($rawValue === null || $rawValue === '') {
            return response()->json([
                'message' => 'El parámetro ciudad_producto_sucursal es requerido y debe ser un entero positivo',
            ], 400);
        }

        $filterValue = (int) $rawValue;

        if ($filterValue <= 0) {
            return response()->json([
                'message' => 'El parámetro ciudad_producto_sucursal es requerido y debe ser un entero positivo',
            ], 400);
        }

        $attribute = $this->attributeRepository->findOneByField('code', 'ciudad_producto_sucursal');

        if (! $attribute) {
            return response()->json([
                'message' => 'El atributo ciudad_producto_sucursal no está configurado en el sistema',
            ], 404);
        }

        $limit = min(max((int) $request->query('limit', 15), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        // Products matching the requested filter value
        $filteredIds = DB::table('attribute_values')
            ->where('attribute_id', $attribute->id)
            ->where('entity_type', 'products')
            ->where('integer_value', $filterValue)
            ->pluck('entity_id');

        // Products always included (value = 10), regardless of filter
        $alwaysIds = DB::table('attribute_values')
            ->where('attribute_id', $attribute->id)
            ->where('entity_type', 'products')
            ->where('integer_value', 10)
            ->pluck('entity_id');

        $allIds = $filteredIds->merge($alwaysIds)->unique()->values();

        $total = $allIds->count();
        $lastPage = (int) ceil($total / $limit);
        $pageIds = $allIds->forPage($page, $limit);

        $products = DB::table('products')
            ->whereIn('id', $pageIds)
            ->get(['id', 'sku', 'name', 'price', 'description']);

        $alwaysIdSet = $alwaysIds->flip();

        $data = $products->map(function ($product) use ($attribute, $filterValue, $alwaysIdSet) {
            $attrValue = DB::table('attribute_values')
                ->where('attribute_id', $attribute->id)
                ->where('entity_type', 'products')
                ->where('entity_id', $product->id)
                ->value('integer_value');

            return [
                'id'                       => $product->id,
                'sku'                      => $product->sku,
                'name'                     => $product->name,
                'price'                    => $product->price,
                'ciudad_producto_sucursal' => $attrValue,
                'siempre_incluido'         => $alwaysIdSet->has($product->id) && $attrValue !== $filterValue,
            ];
        });

        return response()->json([
            'data' => $data->values(),
            'meta' => [
                'current_page' => $page,
                'per_page'     => $limit,
                'total'        => $total,
                'last_page'    => max($lastPage, 1),
            ],
        ]);
    }
}
