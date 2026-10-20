<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Core\Services\Distribuidores;
use Webkul\Lead\Services\CitySyncService;

/**
 * A qué distribuidor le toca cada cliente, y qué se mueve cuando cambia de dueño.
 *
 * Cada distribuidor es una empresa. Las reglas, en el orden en que se aplican:
 *
 *  1. Un cliente tiene UN dueño (`persons.user_id`). Si ya le compró a un
 *     distribuidor de esta ciudad, vuelve a ese: el cliente es de la empresa que lo
 *     atendió, y repartirlo entre dos es lo que las haría pelear.
 *  2. Un cliente nuevo se reparte por turno (round robin) entre los distribuidores
 *     activos de su ciudad: le toca al que hace más tiempo que no recibe uno.
 *  3. Nunca queda a nombre de un administrador, un encargado o el token del agente:
 *     esos roles no reparten. Sin distribuidor posible (ciudad desconocida) queda
 *     sin dueño hasta que se sepa la ciudad.
 *
 * Todos los pedidos abiertos del cliente y su conversación de WhatsApp siguen al
 * dueño: si el cliente se reasigna, se mueven con él.
 */
class Reparto
{
    public function __construct(
        protected Distribuidores $distribuidores,
    ) {}

    /**
     * El distribuidor que atiende a este cliente en esta ciudad.
     *
     * Si el cliente no tenía dueño en la ciudad, se lo asigna (y mueve sus pedidos
     * abiertos de esa ciudad). Null si la ciudad no tiene distribuidores.
     *
     * @param  int|null  $preferido  Un distribuidor de la ciudad que está atendiendo al
     *                               cliente en persona (el Punto): si el cliente no tenía
     *                               dueño, se queda con él en vez de ir al turno.
     */
    public function duenoPara(?int $personId, ?int $pipelineId, ?int $preferido = null): ?int
    {
        $candidatos = $this->distribuidores->deCiudad($pipelineId);

        if (! $candidatos) {
            return null;
        }

        $actual = $this->distribuidores->duenoDe($personId);

        if ($actual && in_array($actual, $candidatos, true)) {
            return $actual;
        }

        $nuevo = $preferido && in_array($preferido, $candidatos, true)
            ? $preferido
            : $this->siguiente((int) $pipelineId);

        if ($nuevo && $personId) {
            $this->asignarCliente($personId, $nuevo);
        }

        return $nuevo;
    }

    /**
     * El siguiente del turno en la ciudad, y lo marca como el último que recibió.
     *
     * Equitativo = le toca al que hace más tiempo que no recibe un cliente. Quien nunca
     * recibió uno va primero (NULL ordena antes en MySQL), y entre empatados decide el
     * id para que el orden sea estable.
     *
     * Con candado: dos pedidos que entran a la vez no pueden caerle al mismo.
     */
    public function siguiente(int $pipelineId): ?int
    {
        return DB::transaction(function () use ($pipelineId) {
            $fila = DB::table('distribuidor_zonas')
                ->join('users', 'users.id', '=', 'distribuidor_zonas.user_id')
                ->where('users.status', 1)
                ->where('distribuidor_zonas.lead_pipeline_id', $pipelineId)
                ->orderBy('distribuidor_zonas.ultima_asignacion_at')
                ->orderBy('distribuidor_zonas.user_id')
                ->lockForUpdate()
                ->first(['distribuidor_zonas.id', 'distribuidor_zonas.user_id']);

            if (! $fila) {
                return null;
            }

            // Con microsegundos (la columna los guarda): con segundos, dos asignaciones en
            // el mismo segundo empataban y le tocaba dos veces al mismo.
            DB::table('distribuidor_zonas')
                ->where('id', $fila->id)
                ->update(['ultima_asignacion_at' => now()->format('Y-m-d H:i:s.u')]);

            return (int) $fila->user_id;
        });
    }

    /**
     * Le pone dueño al pedido según su cliente y su ciudad.
     *
     * Lo llama el listener de `lead.city.synced`, el único punto por el que pasan
     * todos los caminos de alta (Punto, agente, CRM). Devuelve el dueño final.
     */
    public function asignarLead($lead): ?int
    {
        $pipelineId = $lead->lead_pipeline_id ? (int) $lead->lead_pipeline_id : null;
        $actual = $lead->user_id ? (int) $lead->user_id : null;

        /*
         * Sólo cuenta como "preferido" el distribuidor que está en sesión cargando el
         * pedido él mismo. El user_id que mande el agente por la API NO: el agente no
         * elige distribuidor, eso es del turno.
         */
        $enSesion = auth()->guard('user')->id();
        $enSesion = $enSesion ? (int) $enSesion : null;
        $personId = $lead->person_id ? (int) $lead->person_id : null;

        /*
         * Reasignación a mano: un encargado o administrador, desde el CRM, eligió a
         * otro distribuidor de la ciudad como asesor del pedido. Gana sobre el dueño
         * anterior y se lleva al cliente entero —ficha, pedidos abiertos y
         * conversación—, porque un cliente no puede quedar repartido entre dos.
         *
         * Cada update de un pedido vuelve a pasar por acá (lead.city.synced), así que
         * sin esto el reparto deshacía la elección del encargado en el mismo request.
         *
         * No vale para la API: el agente entra con el token de un administrador y el
         * user_id que mande no es una decisión de nadie del equipo.
         */
        if (
            $actual
            && $enSesion
            && ! $this->esPedidoDeLaApi()
            && ! $this->distribuidores->esDistribuidor($enSesion)
            && $this->distribuidores->esDistribuidorDe($actual, $pipelineId)
            && $actual !== $this->distribuidores->duenoDe($personId)
        ) {
            if ($personId) {
                $this->asignarCliente($personId, $actual);
            }

            return $actual;
        }

        $preferido = $actual && $actual === $enSesion ? $actual : null;

        $dueno = $this->duenoPara($personId, $pipelineId, $preferido);

        // Sin distribuidor posible: no se deja a nombre de un rol que no reparte.
        if (! $dueno && $actual && ! $this->distribuidores->esDistribuidor($actual)) {
            $dueno = null;
        } elseif (! $dueno) {
            return $actual;
        }

        if ($dueno !== $actual) {
            $lead->newQuery()->whereKey($lead->id)->update(['user_id' => $dueno]);
            $lead->user_id = $dueno;

            Log::info('Pedido asignado por reparto', [
                'lead_id'     => $lead->id,
                'pipeline_id' => $pipelineId,
                'antes'       => $actual,
                'ahora'       => $dueno,
            ]);
        }

        return $dueno;
    }

    /**
     * Pasa el cliente a otro distribuidor: su ficha, sus pedidos abiertos y su
     * conversación de WhatsApp.
     *
     * Los pedidos cerrados (entregados, cancelados) se quedan con quien los entregó:
     * son su historia y su caja.
     */
    public function asignarCliente(int $personId, int $userId): void
    {
        DB::table('persons')->where('id', $personId)->update(['user_id' => $userId]);

        $abiertos = DB::table('leads')
            ->where('person_id', $personId)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $userId))
            ->whereNotIn('lead_pipeline_stage_id', $this->etapasCerradas())
            ->pluck('id');

        if ($abiertos->isNotEmpty()) {
            DB::table('leads')->whereIn('id', $abiertos)->update(['user_id' => $userId]);
        }

        /*
         * La conversación ya "sigue" al cliente para la bandeja (se filtra por el dueño
         * del cliente). Lo que hay que mover es la derivación abierta: si estaba tomada
         * por el distribuidor anterior, quedaría a nombre de alguien que ya no la ve.
         */
        DB::table('whatsapp_conversations')
            ->where('person_id', $personId)
            ->whereIn('handoff_state', ['requested', 'assigned'])
            ->update(['assigned_user_id' => $userId, 'assigned_at' => now()]);
    }

    /**
     * Reparte los clientes de un distribuidor entre los demás de su ciudad.
     *
     * Para cuando se lo da de baja: sus clientes no pueden quedar a nombre de alguien
     * que ya no entra al CRM. Cada cliente se reparte por el turno de SU ciudad.
     *
     * @return array{repartidos: int, sin_ciudad: int}
     */
    public function repartirClientesDe(int $userId): array
    {
        $resultado = ['repartidos' => 0, 'sin_ciudad' => 0];

        foreach (DB::table('persons')->where('user_id', $userId)->pluck('id') as $personId) {
            $ciudad = $this->ciudadDelCliente((int) $personId);
            $nuevo = $ciudad ? $this->siguienteSalvo($ciudad, $userId) : null;

            if ($nuevo) {
                $this->asignarCliente((int) $personId, $nuevo);
                $resultado['repartidos']++;
            } else {
                // Sin ciudad o sin otro distribuidor: queda sin dueño y lo ven los
                // encargados, en vez de seguir a nombre del que se fue.
                DB::table('persons')->where('id', $personId)->update(['user_id' => null]);
                $resultado['sin_ciudad']++;
            }
        }

        return $resultado;
    }

    /**
     * El turno de la ciudad salteando a un distribuidor (el que se da de baja, que
     * puede seguir activo en `users` mientras se lo reparte).
     */
    protected function siguienteSalvo(int $pipelineId, int $excluido): ?int
    {
        $candidatos = array_diff($this->distribuidores->deCiudad($pipelineId), [$excluido]);

        if (! $candidatos) {
            return null;
        }

        for ($i = 0; $i <= count($candidatos); $i++) {
            $turno = $this->siguiente($pipelineId);

            if ($turno !== $excluido) {
                return $turno;
            }
        }

        return null;
    }

    /**
     * La ciudad del cliente: la que tiene cargada en su ficha, o la de su último
     * pedido si la ficha no la tiene.
     */
    public function ciudadDelCliente(int $personId): ?int
    {
        $deLaFicha = DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->where('attributes.code', CitySyncService::PERSON_CITY_ATTRIBUTE_CODE)
            ->where('attribute_values.entity_type', 'persons')
            ->where('attribute_values.entity_id', $personId)
            ->value('attribute_values.integer_value');

        if ($deLaFicha) {
            return (int) $deLaFicha;
        }

        $delPedido = DB::table('leads')
            ->where('person_id', $personId)
            ->whereNotNull('lead_pipeline_id')
            ->orderByDesc('id')
            ->value('lead_pipeline_id');

        return $delPedido ? (int) $delPedido : null;
    }

    /**
     * Si el pedido llega por la API (el agente de IA o cualquier integración con
     * token), donde el usuario autenticado es el dueño del token y no alguien
     * eligiendo un asesor.
     */
    protected function esPedidoDeLaApi(): bool
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        $request = request();

        return $request->is('api/*') || (bool) $request->bearerToken();
    }

    /** @return array<int, int> */
    protected function etapasCerradas(): array
    {
        return DB::table('lead_pipeline_stages')
            ->whereIn('code', CitySyncService::CLOSED_STAGE_CODES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all() ?: [0];
    }
}
