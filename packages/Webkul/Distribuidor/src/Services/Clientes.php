<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Contact\Models\PersonProxy;

/**
 * La agenda de clientes del distribuidor: a quién le vende, cuántos envases tiene
 * cada uno y cuándo fue la última vez que le entregó.
 *
 * Reemplaza al apartado de Bidones, que listaba SÓLO a los que debían envases. Ese
 * recorte dejaba afuera el caso más común de la calle: el distribuidor se acuerda
 * del cliente pero no del teléfono, y necesita llegar a su ficha sin pasar por un
 * pedido. Los que deben envases siguen apareciendo arriba, porque se ordena por
 * saldo.
 */
class Clientes
{
    public function __construct(
        protected Bidones $bidones,
        protected Ubicacion $ubicacion,
        protected Recordatorios $recordatorios,
    ) {}

    /**
     * Si la tabla de entregas ya existe.
     *
     * Mismo motivo que en Bidones: el código y las migraciones se despliegan en
     * pasos distintos, y entre uno y otro esta pantalla no puede tirar 500 — es la
     * agenda del que está en la calle. Sin la tabla no hay fecha de entrega y el
     * resto de la ficha sirve igual.
     */
    protected function tablaDeEntregas(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('distribuidor_entregas');
    }

    /**
     * Los clientes de las ciudades dadas, con su saldo de envases y su última
     * entrega.
     *
     * @param  array<int, int>|null  $ciudades  null = todas (usuario sin restricción)
     * @return array<int, array{person_id:int, nombre:string, telefono:?string, saldo:int, ultima_entrega:?string, ubicacion:?array, recordatorio:?array}>
     */
    public function listar(
        ?array $ciudades = null,
        ?string $busqueda = null,
        int $limite = 30,
        int $offset = 0,
    ): array {
        $ids = $this->idsDeClientes($ciudades, $busqueda, $limite, $offset);

        if (! $ids) {
            return [];
        }

        /*
         * Los tres datos se traen en TRES consultas y no una por cliente. Pedirlo
         * fila por fila es un N+1 de 30 idas y vueltas, y esto corre en un celular
         * con datos móviles: ahí cada consulta se paga en segundos, no en
         * milisegundos.
         */
        $saldos = $this->bidones->saldosDe($ids);
        $entregas = $this->ultimasEntregas($ids);
        // El domicilio viaja con la fila para que "Editar domicilio" abra el mapa
        // sobre el pin guardado y no en el centro de la provincia.
        $ubicaciones = $this->ubicacion->deVarios($ids);
        $recordatorios = $this->estadosDeRecordatorio($ids);

        $personas = PersonProxy::modelClass()::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        /*
         * Se recorre $ids y no $personas: el orden lo decidió la consulta de arriba
         * —por saldo y por última venta— y un keyBy no lo conserva.
         */
        $filas = [];

        foreach ($ids as $id) {
            $persona = $personas->get($id);

            if (! $persona) {
                continue;
            }

            $filas[] = [
                'person_id' => (int) $id,
                'nombre'    => (string) $persona->name,
                'telefono'  => $this->telefonoDe($persona),
                'saldo'     => (int) ($saldos[$id] ?? 0),
                /*
                 * null tiene DOS significados y la pantalla los distingue: el cliente
                 * nunca recibió nada, o recibió antes de que existiera el registro de
                 * entregas. No se inventa una fecha para tapar el segundo caso.
                 */
                'ultima_entrega' => $entregas[$id] ?? null,
                'ubicacion'      => $ubicaciones[$id] ?? null,
                'recordatorio'   => $recordatorios[$id] ?? null,
            ];
        }

        return $filas;
    }

    /**
     * Los ids de los clientes de la zona, ya ordenados y paginados.
     *
     * Cliente es quien tiene AL MENOS un pedido en una de las ciudades del
     * distribuidor. No se listan todas las `persons` del CRM: ahí hay contactos de
     * otras ciudades y de otros circuitos, y el distribuidor no reparte para ellos.
     *
     * @param  array<int, int>|null  $ciudades
     * @return array<int, int>
     */
    protected function idsDeClientes(
        ?array $ciudades,
        ?string $busqueda,
        int $limite,
        int $offset,
    ): array {
        /*
         * Sin alias de tabla a propósito. Laravel le aplica el prefijo TAMBIÉN al
         * alias, así que `leads as l` termina siendo `kl_l` y cualquier fragmento
         * crudo que diga `l.id` falla con "Unknown column". El prefijo se escribe a
         * mano sólo en los fragmentos CRUDOS: en join() y whereIn() lo agrega
         * Laravel solo, y ponerlo dos veces lo duplica.
         */
        $p = DB::getTablePrefix();

        $query = DB::table('leads')
            ->join('persons', 'persons.id', '=', 'leads.person_id')
            ->whereNotNull('leads.person_id')
            ->when(
                $ciudades !== null,
                // El array vacío significa "no ve ninguna ciudad", no "ve todas":
                // sin el [0] el whereIn se cae y devolvería la agenda entera.
                fn ($q) => $q->whereIn('leads.lead_pipeline_id', $ciudades ?: [0]),
            );

        $this->aplicarBusqueda($query, $busqueda);

        /*
         * El orden: primero los que deben envases, después por venta más reciente.
         *
         * Alfabético sería inútil sin escribir nada en el buscador, y la lista se
         * abre sin nada escrito. Los que deben envases arriba es lo que hacía el
         * apartado de Bidones, que esta pantalla reemplaza: ese dato era el motivo
         * de la pantalla y no se pierde al ampliarla.
         */
        $saldo = $this->tablaDeBidones()
            ? "coalesce((select sum({$p}distribuidor_bidones.entregados) - sum({$p}distribuidor_bidones.retirados)"
                ." from {$p}distribuidor_bidones"
                ." where {$p}distribuidor_bidones.person_id = {$p}leads.person_id), 0)"
            : '0';

        return $query->groupBy('leads.person_id')
            ->orderByRaw("case when {$saldo} > 0 then 0 else 1 end")
            ->orderByRaw("max({$p}leads.id) desc")
            ->limit($limite)
            ->offset($offset)
            ->selectRaw("{$p}leads.person_id as person_id")
            ->pluck('person_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Filtra por nombre o por teléfono con el MISMO texto.
     *
     * Un buscador con dos campos obliga a elegir antes de escribir, y el que está
     * parado en la puerta no quiere elegir: escribe lo que se acuerda. Los dígitos
     * se limpian porque el teléfono se guarda con el formato que tenga cada ficha
     * ("+54 388 15 …") y nadie va a tipear los espacios iguales.
     */
    protected function aplicarBusqueda($query, ?string $busqueda): void
    {
        $busqueda = trim((string) $busqueda);

        if ($busqueda === '') {
            return;
        }

        $digitos = preg_replace('/\D+/', '', $busqueda);

        $query->where(function ($w) use ($busqueda, $digitos) {
            $w->where('persons.name', 'like', '%'.$busqueda.'%');

            /*
             * Los últimos 8 dígitos y no todos: el distribuidor tipea "155123456" y
             * la ficha guarda "+5493881234567". Buscar el número entero no matchea
             * nunca; la cola sí, y es lo bastante larga para no traer a otro.
             */
            if (strlen($digitos) >= 6) {
                $w->orWhere('persons.contact_numbers', 'like', '%'.substr($digitos, -8).'%');
            }
        });
    }

    /**
     * La fecha de la última entrega de cada cliente.
     *
     * Sale de `distribuidor_entregas.entregado_at` y NO del lead: la etapa
     * `pedido-entregado` dice QUE se entregó pero no cuándo, y `updated_at` del lead
     * se mueve con cualquier edición posterior —cobrar, corregir un producto— así que
     * mostraría la fecha de la última edición disfrazada de fecha de entrega.
     *
     * @param  array<int, int>  $personIds
     * @return array<int, string> person_id => fecha
     */
    protected function ultimasEntregas(array $personIds): array
    {
        if (! $personIds || ! $this->tablaDeEntregas()) {
            return [];
        }

        $p = DB::getTablePrefix();

        return DB::table('distribuidor_entregas')
            ->join('leads', 'leads.id', '=', 'distribuidor_entregas.lead_id')
            ->whereIn('leads.person_id', $personIds)
            ->groupBy('leads.person_id')
            ->selectRaw(
                "{$p}leads.person_id as person_id,"
                ." max({$p}distribuidor_entregas.entregado_at) as ultima"
            )
            ->pluck('ultima', 'person_id')
            ->map(fn ($f) => (string) $f)
            ->all();
    }

    protected function tablaDeBidones(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('distribuidor_bidones');
    }

    /**
     * El estado del recordatorio de recompra de cada cliente de la página.
     *
     * Una consulta para todos, igual que los saldos y los domicilios. Devuelve sólo
     * lo que la agenda dibuja: si está encendido, si el cliente se dio de baja y
     * cuándo le toca.
     *
     * @param  array<int, int>  $personIds
     * @return array<int, array{activo:bool, baja:bool, proximo_at:?string, intentos:int}>
     */
    protected function estadosDeRecordatorio(array $personIds): array
    {
        if (! $personIds || ! Schema::hasTable('distribuidor_recordatorios')) {
            return [];
        }

        return DB::table('distribuidor_recordatorios')
            ->whereIn('person_id', $personIds)
            ->get()
            ->mapWithKeys(fn ($f) => [
                (int) $f->person_id => [
                    'activo' => (bool) $f->activo,
                    // La baja la pidió el CLIENTE y no se puede deshacer desde el
                    // interruptor: la pantalla las muestra distinto por eso.
                    'baja'       => (bool) $f->baja_at,
                    'proximo_at' => $f->proximo_at,
                    'intentos'   => (int) $f->intentos,
                ],
            ])
            ->all();
    }

    protected function telefonoDe($persona): ?string
    {
        $numeros = (array) $persona->contact_numbers;

        return $numeros ? ($numeros[0]['value'] ?? null) : null;
    }
}
