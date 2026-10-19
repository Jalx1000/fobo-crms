<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Warehouse\Repositories\WarehouseRepository;

class SucursalController extends Controller
{
    public function __construct(
        protected WarehouseRepository $warehouseRepository
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/kohlberg/sucursales",
     *     summary="Sucursales para el mensaje de retiro del agente",
     *     description="Devuelve solo ciudad, dirección, horarios, enlace de mapa y teléfono de cada sucursal. Una ciudad sin sucursal devuelve una lista vacía. Los campos que la sucursal no tiene cargados vienen en null: si `direccion` es null, el agente no debe mandar dirección.",
     *     operationId="getSucursalesAgente",
     *     tags={"Sucursales"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="ciudad",
     *         in="query",
     *         required=false,
     *         description="Ciudad (recomendado). No distingue mayúsculas ni tildes. Sin este parámetro devuelve todas las sucursales.",
     *
     *         @OA\Schema(type="string", example="Cochabamba")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Sucursales de la ciudad (o todas)",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="sucursales", type="array",
     *
     *                 @OA\Items(
     *
     *                     @OA\Property(property="ciudad", type="string", example="Cochabamba"),
     *                     @OA\Property(property="direccion", type="string", nullable=true, example="Calle Mayor Rocha No 460 entre Jose Marti y Tarapaca."),
     *                     @OA\Property(property="horarios", type="string", nullable=true, example="Lunes a Viernes de 9:00hrs a 17:00hrs · Sábado de 9:00hrs a 13:00hrs"),
     *                     @OA\Property(property="mapa_url", type="string", nullable=true, example="https://maps.app.goo.gl/S9ACh6uam7nXdmgn6"),
     *                     @OA\Property(property="telefono", type="string", nullable=true, example="+591 67400062")
     *                 )
     *             )
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
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        // Opcional: sin ciudad devuelve todas las sucursales emparejadas con un pipeline.
        $ciudad = trim((string) $request->query('ciudad', '')) ?: null;

        return response()->json([
            'sucursales' => $this->warehouseRepository->sucursalesParaAgente($ciudad),
        ]);
    }
}
