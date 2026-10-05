<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Distribuidor\Models\MovimientoBidonesProxy;

/**
 * El control de bidones: cuántos tiene cada cliente y cuántos movió cada
 * distribuidor.
 *
 * Los bidones son envases que vuelven: el distribuidor deja llenos y se lleva
 * vacíos. Lo que el negocio necesita saber es cuántos quedaron en la casa del
 * cliente —para ir a buscarlos— y cuánto movió cada distribuidor en el día, para
 * cuadrar contra lo que trae de vuelta en la camioneta.
 *
 * El saldo se CALCULA sumando los movimientos, no se guarda. Un contador guardado
 * se desincroniza en cuanto un movimiento se corrige o se borra, y entonces hay
 * dos verdades y ninguna forma de saber cuál está mal.
 */
class Bidones
{
    /**
     * Si la tabla de movimientos ya existe.
     *
     * El código y las migraciones se despliegan en pasos distintos, y entre uno y
     * otro el Punto quedaba tirando 500 en la pantalla principal del distribuidor
     * —que está en la calle y no puede esperar—. Sin bidones todavía, el saldo es 0
     * y el resto del Punto funciona igual; en cuanto corre la migración, el control
     * aparece solo.
     *
     * Memorizado: esto se consulta en cada búsqueda de contacto.
     */
    protected function tablaLista(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('distribuidor_bidones');
    }

    /**
     * Cuántos bidones tiene el cliente en su casa.
     *
     * Puede dar negativo, y no se corrige a cero a propósito: significa que se
     * retiraron más de los que este sistema registró haber dejado —envases de
     * antes de que existiera el control, o un movimiento mal cargado—. Taparlo con
     * un max(0) esconde el error justo cuando hay que verlo.
     */
    public function saldoDe(?int $personId): int
    {
        if (! $personId || ! $this->tablaLista()) {
            return 0;
        }

        $fila = MovimientoBidonesProxy::modelClass()::query()
            ->where('person_id', $personId)
            ->selectRaw('coalesce(sum(entregados), 0) as dejados, coalesce(sum(retirados), 0) as traidos')
            ->first();

        return (int) $fila->dejados - (int) $fila->traidos;
    }

    /**
     * El resumen del cliente para la ficha del CRM.
     *
     * Devuelve el saldo y ademas desde cuando: "tiene 3 bidones" sin fecha no dice si
     * se los dejaron ayer o hace cuatro meses, y esa diferencia es justamente la que
     * decide si hay que ir a buscarlos.
     *
     * @return array{saldo:int, entregados:int, retirados:int, ultimo:?string, movimientos:int}
     */
    public function resumenDe(?int $personId): array
    {
        $vacio = ['saldo' => 0, 'entregados' => 0, 'retirados' => 0, 'ultimo' => null, 'movimientos' => 0];

        if (! $personId || ! $this->tablaLista()) {
            return $vacio;
        }

        $fila = MovimientoBidonesProxy::modelClass()::query()
            ->where('person_id', $personId)
            ->selectRaw(
                'coalesce(sum(entregados), 0) as dejados,'
                .' coalesce(sum(retirados), 0) as traidos,'
                .' count(*) as movimientos,'
                .' max(created_at) as ultimo'
            )
            ->first();

        if (! $fila || ! (int) $fila->movimientos) {
            return $vacio;
        }

        return [
            'saldo'       => (int) $fila->dejados - (int) $fila->traidos,
            'entregados'  => (int) $fila->dejados,
            'retirados'   => (int) $fila->traidos,
            'ultimo'      => $fila->ultimo,
            'movimientos' => (int) $fila->movimientos,
        ];
    }

    /**
     * El saldo de varios clientes de una sola consulta.
     *
     * Para las listas del Punto: pedirlo cliente por cliente es un N+1 que se nota
     * en el celular, que es donde corre esto.
     *
     * @param  array<int, int>  $personIds
     * @return array<int, int> person_id => saldo
     */
    public function saldosDe(array $personIds): array
    {
        if (! $personIds || ! $this->tablaLista()) {
            return [];
        }

        return MovimientoBidonesProxy::modelClass()::query()
            ->whereIn('person_id', $personIds)
            ->groupBy('person_id')
            ->selectRaw('person_id, coalesce(sum(entregados), 0) - coalesce(sum(retirados), 0) as saldo')
            ->pluck('saldo', 'person_id')
            ->map(fn ($s) => (int) $s)
            ->all();
    }

    /**
     * Registra lo que se dejó y lo que se retiró en una visita.
     *
     * Devuelve null cuando no hay nada que registrar, para que quien llama no
     * tenga que decidirlo: una entrega sin bidones es lo más común si el cliente
     * compra agua en botella, y guardar una fila de ceros sólo ensucia el libro.
     */
    public function registrar(
        int $personId,
        ?int $leadId,
        int $entregados,
        int $retirados,
        ?int $userId = null,
    ): ?object {
        if (($entregados <= 0 && $retirados <= 0) || ! $this->tablaLista()) {
            return null;
        }

        return MovimientoBidonesProxy::modelClass()::create([
            'person_id'  => $personId,
            'lead_id'    => $leadId,
            'user_id'    => $userId ?? auth()->guard('user')->id(),
            'entregados' => max(0, $entregados),
            'retirados'  => max(0, $retirados),
        ]);
    }

    /**
     * Lo que movió el distribuidor hoy, para la Caja.
     *
     * Se cuadra igual que la plata: lo que dejó salió de la camioneta y lo que
     * retiró tiene que volver a ella.
     *
     * @return array{entregados:int, retirados:int, visitas:int, en_la_calle:int}
     */
    public function delDia(?int $userId = null): array
    {
        $userId ??= auth()->guard('user')->id();

        if (! $this->tablaLista()) {
            return ['entregados' => 0, 'retirados' => 0, 'visitas' => 0, 'en_la_calle' => 0];
        }

        $fila = MovimientoBidonesProxy::modelClass()::query()
            ->where('user_id', $userId)
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw('coalesce(sum(entregados), 0) as dejados, coalesce(sum(retirados), 0) as traidos, count(*) as visitas')
            ->first();

        $dejados = (int) $fila->dejados;
        $traidos = (int) $fila->traidos;

        return [
            'entregados' => $dejados,
            'retirados'  => $traidos,
            'visitas'    => (int) $fila->visitas,
            // Los que salieron y no volvieron: lo que falta recuperar de la vuelta
            // de hoy. Es el número que se mira al cerrar el día.
            'en_la_calle' => $dejados - $traidos,
        ];
    }

    /**
     * Los clientes con bidones sin devolver, para ir a buscarlos.
     *
     * @return array<int, array{person_id:int, nombre:string, saldo:int, ultimo:string}>
     */
    public function pendientesDeDevolucion(?array $ciudades = null, int $limite = 50): array
    {
        if (! $this->tablaLista()) {
            return [];
        }

        /*
         * Sin alias de tabla a propósito. Laravel le aplica el prefijo de tablas
         * TAMBIÉN al alias, así que `distribuidor_bidones as b` termina siendo
         * `kl_b` y cualquier SQL crudo que diga `b.person_id` falla con "Unknown
         * column". Los nombres completos son más largos de leer pero no mienten.
         *
         * El prefijo se escribe a mano sólo en los fragmentos CRUDOS: en join(),
         * groupBy() y whereIn() lo agrega Laravel solo, y ponerlo dos veces lo
         * duplica.
         */
        $p = DB::getTablePrefix();
        $saldo = "sum({$p}distribuidor_bidones.entregados) - sum({$p}distribuidor_bidones.retirados)";

        return DB::table('distribuidor_bidones')
            ->join('persons', 'persons.id', '=', 'distribuidor_bidones.person_id')
            ->when($ciudades !== null, function ($q) use ($ciudades) {
                // La ciudad del cliente sale de sus leads: es el mismo criterio que
                // usa el resto del Punto para saber de qué ciudad es alguien.
                $q->whereExists(fn ($e) => $e->selectRaw('1')
                    ->from('leads')
                    ->whereColumn('leads.person_id', 'distribuidor_bidones.person_id')
                    ->whereIn('leads.lead_pipeline_id', $ciudades ?: [0]));
            })
            ->groupBy('distribuidor_bidones.person_id', 'persons.name')
            ->havingRaw("{$saldo} > 0")
            ->orderByRaw("{$saldo} desc")
            ->limit($limite)
            ->selectRaw(
                "{$p}distribuidor_bidones.person_id as person_id,"
                ." {$p}persons.name as nombre,"
                ." {$saldo} as saldo,"
                ." max({$p}distribuidor_bidones.created_at) as ultimo"
            )
            ->get()
            ->map(fn ($r) => [
                'person_id' => (int) $r->person_id,
                'nombre'    => (string) $r->nombre,
                'saldo'     => (int) $r->saldo,
                'ultimo'    => (string) $r->ultimo,
            ])
            ->all();
    }
}
