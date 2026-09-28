<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Services\Responsables;
use Webkul\User\Models\User;

/**
 * El equipo de ventas de Imprimir (lista del 15/09/2026): quién atiende qué
 * producto en qué ciudad, con teléfono y horario.
 *
 * Se identifica a cada persona por email y a cada producto por SKU: los ids
 * cambian entre bases, esos no. Idempotente: vuelve a dejar la matriz de cada
 * persona como está acá (ver `Responsables::guardarDe`, que conserva el turno
 * de las casillas que siguen). Quien no figure en la lista no se toca.
 *
 *     php artisan db:seed --class=Database\\Seeders\\EquipoVentasImprimirSeeder
 */
class EquipoVentasImprimirSeeder extends Seeder
{
    protected const STRETCH = 'CM_00001';

    protected const MAGIA_VERDE = 'CM_00002';

    protected const HULES = 'CM_00003';

    protected const TAPAS = 'CM_00004';

    protected const TODOS = [self::STRETCH, self::MAGIA_VERDE, self::HULES, self::TAPAS];

    protected const HORARIO = 'de 08:00 a 19:00';

    /** email => [telefono, [sku => [ciudad, ...]]] */
    protected const EQUIPO = [
        'bulmarovaca@imprimir.com.bo' => ['692-26171', [self::STRETCH => ['Bolivia']]],
        'eduardo.lujan@imprimir.com.bo' => ['787-40409', [self::MAGIA_VERDE => ['Bolivia']]],
        'fabio-sandoval@imprimir.com.bo' => ['787-40409', [self::HULES => ['Santa Cruz']]],
        'mauricio.garces@imprimir.com.bo' => [null, 'todos' => 'Cochabamba'],
        'gabriela-marconi@imprimir.com.bo' => [null, 'todos' => 'La Paz'],
        'Mariano.pinell.m@gmail.com' => ['75599756', 'todos' => 'Bolivia'],
    ];

    public function run(): void
    {
        $productos = Product::whereIn('sku', self::TODOS)->pluck('id', 'sku');

        $responsables = app(Responsables::class);

        foreach (self::EQUIPO as $email => $datos) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->command?->warn("No existe el usuario {$email}: se salta.");

                continue;
            }

            $user->forceFill(['telefono' => $datos[0], 'horario' => self::HORARIO])->save();

            // «todos» = los cuatro productos en una ciudad.
            $porSku = isset($datos['todos'])
                ? array_fill_keys(self::TODOS, [$datos['todos']])
                : $datos[1];

            $matriz = [];

            foreach ($porSku as $sku => $ciudades) {
                if (isset($productos[$sku])) {
                    $matriz[$productos[$sku]] = $ciudades;
                }
            }

            $responsables->guardarDe($user->id, $matriz);

            $this->command?->info("{$user->name}: ".collect($porSku)->map(fn ($c, $sku) => $sku.'→'.implode('/', $c))->join(', '));
        }
    }
}
