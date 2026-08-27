<?php

namespace Webkul\Inmobiliaria\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;

/**
 * Lectura del catálogo para el agente.
 *
 * Ninguna respuesta de este controlador contiene la ubicación exacta: la ficha
 * se arma con `paraAgente()`, que es una lista blanca. Agregar una columna a la
 * tabla no la expone sola.
 */
class CatalogoController extends Controller
{
    public function __construct(protected InmuebleRepository $inmuebles) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'operacion'       => 'nullable|string',
            'tipo'            => 'nullable|string',
            'zonas'           => 'nullable|array',
            'zonas.*'         => 'string|max:120',
            'precio_min'      => 'nullable|numeric|min:0',
            'precio_max'      => 'nullable|numeric|min:0',
            'moneda'          => 'nullable|in:USD,BOB',
            'dormitorios_min' => 'nullable|integer|min:0',
            'limite'          => 'nullable|integer|min:1',
        ]);

        $resultados = $this->inmuebles->buscar($filtros);

        return response()->json([
            'resultados' => $resultados->map(fn ($i) => $i->paraAgente())->all(),
            'total'      => $resultados->count(),
            // Se declara explícitamente para que el agente no tenga que
            // deducirlo: los precios vienen en la moneda de cada ficha.
            'nota'       => 'Los precios se muestran en la moneda de cada inmueble. No hay conversión.',
        ]);
    }

    public function show(string $codigo): JsonResponse
    {
        $inmueble = $this->inmuebles->porCodigo($codigo);

        if (! $inmueble) {
            // 404 y no lista vacía: el agente citó un código que el cliente le
            // dio, y necesita poder decirle que no lo encuentra.
            return response()->json(['message' => "No existe un inmueble disponible con el código {$codigo}."], 404);
        }

        return response()->json($inmueble->paraAgente(completo: true));
    }
}
