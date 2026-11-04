<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Services\Responsables;
use Webkul\User\Repositories\UserRepository;

/**
 * Equipo de ventas: quién atiende qué producto en qué ciudad, de un vistazo.
 *
 * Solo lectura. Se edita en cada usuario (Configuración → Usuarios, sección
 * «Atiende») o en cada producto: el dato pertenece a la persona, y acá lo que
 * hace falta es ver el conjunto -qué casilla quedó sin nadie, quién cubre de
 * más- sin abrir doce fichas.
 */
class EquipoVentasController extends Controller
{
    public function __construct(
        protected UserRepository $users,
        protected ProductRepository $products,
    ) {}

    public function index(): View
    {
        $productos = $this->products->getModel()->newQuery()
            ->with('responsables.user:id,name,status')
            ->orderBy('id')
            ->get();

        $ciudades = Responsables::ciudades();

        // [product_id => [ciudad => [usuarios...]]], solo activos, por nombre.
        $matriz = [];

        foreach ($productos as $producto) {
            foreach ($ciudades as $ciudad) {
                $matriz[$producto->id][$ciudad] = $producto->responsables
                    ->filter(fn ($r) => $r->user && (int) $r->user->status === 1 && Responsables::mismaCiudad($r->ciudad, $ciudad))
                    ->map->user
                    ->sortBy('name')
                    ->values();
            }
        }

        $asesores = $this->users->getModel()->newQuery()
            ->where('status', 1)
            ->whereHas('atiende')
            ->with('atiende.product:id,name')
            ->orderBy('name')
            ->get();

        return view('admin::settings.equipo-ventas.index', compact('productos', 'ciudades', 'matriz', 'asesores'));
    }
}
