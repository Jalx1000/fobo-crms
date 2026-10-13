<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\Event;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Lead\Models\LeadProxy;

/**
 * Deja rastro de cada edición de un pedido.
 *
 * El negocio permite editar un pedido incluso después de cobrado y entregado, con una
 * sola condición: que quede registro. Sin eso, un pedido que hoy dice $6.000 y se
 * cobró $8.000 no tiene explicación, y la caja del día no cuadra contra la plata real
 * sin que nadie pueda reconstruir por qué.
 *
 * El registro va como ACTIVIDAD del lead y no en una tabla propia, por dos razones:
 * queda en la misma línea de tiempo que el encargado ya mira —al lado de las notas y
 * las llamadas, sin otra pantalla que aprender— y no necesita migración.
 *
 * Se escribe el detalle en texto y no un JSON: lo va a leer una persona meses después,
 * y "Bidón 20L: 4 → 3" se entiende sin herramientas.
 */
class RegistroDeEdicion
{
    public function __construct(protected ActivityRepository $actividades) {}

    /**
     * @param  array{total:float, lineas:array}  $antes
     * @param  array{total:float, lineas:array}  $despues
     */
    public function guardar(int $leadId, array $antes, array $despues, float $cobrado = 0.0): void
    {
        $lead = LeadProxy::modelClass()::find($leadId);

        if (! $lead) {
            return;
        }

        Event::dispatch('activity.create.before');

        $actividad = $this->actividades->create([
            /*
             * Tipo 'note' y no uno propio: Krayin filtra y muestra las actividades por
             * tipo, y un tipo inventado no aparecería en ninguna vista existente. El
             * texto ya dice que es una edición.
             */
            'type'    => 'note',
            'comment' => $this->texto($antes, $despues, $cobrado),
            'is_done' => 1,
            'user_id' => auth()->guard('user')->id(),
        ]);

        $lead->activities()->attach($actividad->id);

        Event::dispatch('activity.create.after', $actividad);
    }

    /**
     * El texto del registro: qué cambió, cuánto era y cuánto es.
     */
    protected function texto(array $antes, array $despues, float $cobrado): string
    {
        $lineas = ['Pedido editado desde el Punto.'];

        $lineas[] = sprintf(
            'Total: %s → %s',
            $this->plata($antes['total']),
            $this->plata($despues['total']),
        );

        foreach ($this->diferencias($antes['lineas'], $despues['lineas']) as $cambio) {
            $lineas[] = $cambio;
        }

        /*
         * La advertencia sólo si hay plata cobrada que ya no coincide. Es el dato que
         * convierte el registro en algo accionable: alguien tiene que devolver o cobrar
         * la diferencia, y si no está escrito acá no se entera nadie.
         */
        $diferencia = round($cobrado - $despues['total'], 2);

        if ($cobrado > 0 && abs($diferencia) >= 0.01) {
            $lineas[] = $diferencia > 0
                ? sprintf('⚠ Cobrado %s: hay que devolver %s.', $this->plata($cobrado), $this->plata($diferencia))
                : sprintf('⚠ Cobrado %s: falta cobrar %s.', $this->plata($cobrado), $this->plata(abs($diferencia)));
        }

        return implode("\n", $lineas);
    }

    /**
     * Qué líneas se agregaron, se quitaron o cambiaron de cantidad.
     *
     * Se compara por NOMBRE porque es lo que el retrato guarda, y es lo único que
     * sigue significando algo si el producto desaparece del catálogo.
     *
     * @return array<int, string>
     */
    protected function diferencias(array $antes, array $despues): array
    {
        $porNombre = fn ($lineas) => collect($lineas)->keyBy('nombre');

        $a = $porNombre($antes);
        $d = $porNombre($despues);

        $cambios = [];

        foreach ($d as $nombre => $linea) {
            $vieja = $a->get($nombre);

            if (! $vieja) {
                $cambios[] = sprintf('+ %s: agregado (%s)', $nombre, $this->cantidad($linea['cantidad']));

                continue;
            }

            if ((float) $vieja['cantidad'] !== (float) $linea['cantidad']) {
                $cambios[] = sprintf(
                    '· %s: %s → %s',
                    $nombre,
                    $this->cantidad($vieja['cantidad']),
                    $this->cantidad($linea['cantidad']),
                );
            }

            if ((float) $vieja['precio'] !== (float) $linea['precio']) {
                $cambios[] = sprintf(
                    '· %s: precio %s → %s',
                    $nombre,
                    $this->plata($vieja['precio']),
                    $this->plata($linea['precio']),
                );
            }
        }

        foreach ($a as $nombre => $linea) {
            if (! $d->has($nombre)) {
                $cambios[] = sprintf('− %s: quitado (%s)', $nombre, $this->cantidad($linea['cantidad']));
            }
        }

        return $cambios;
    }

    protected function plata(float $monto): string
    {
        return '$ '.number_format($monto, 2, ',', '.');
    }

    /** Sin decimales cuando son enteros: "3" se lee mejor que "3,00". */
    protected function cantidad(float $valor): string
    {
        return $valor === floor($valor) ? (string) (int) $valor : rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',');
    }
}
