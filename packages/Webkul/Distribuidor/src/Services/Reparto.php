<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Core\Services\Distribuidores;
use Webkul\Lead\Services\CitySyncService;

/**
 * A quién le toca cada pedido, y qué se mueve cuando un cliente cambia de dueño.
 *
 * Cada distribuidor es una empresa. Las reglas, en el orden en que se aplican:
 *
 *  1. Un cliente tiene UN dueño (`persons.user_id`). Si es de un distribuidor de esta
 *     ciudad, todos sus pedidos van a ese distribuidor: el cliente es de la empresa
 *     que lo cargó o a la que se lo asignaron.
 *  2. El cliente que un distribuidor está atendiendo en persona (el Punto) y no tenía
 *     dueño, pasa a ser suyo.
 *  3. Todo lo demás —pedidos del agente de IA para clientes sin distribuidor, pedidos
 *     sin ciudad, clientes de un distribuidor dado de baja— queda a nombre del
 *     RESPONSABLE (Huascar), que lo reparte a mano. Cuando lo asigna a un
 *     distribuidor, el cliente entero pasa a ese distribuidor (regla 1 de ahí en más).
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
     * El distribuidor dueño de este cliente en esta ciudad, o null si no tiene uno.
     *
     * Si el cliente no tenía dueño y lo está atendiendo un distribuidor de la ciudad
     * (el Punto), queda a nombre de ese distribuidor.
     *
     * @param  int|null  $preferido  Distribuidor de la ciudad que está atendiendo al cliente.
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

        if ($preferido && in_array($preferido, $candidatos, true)) {
            if ($personId) {
                $this->asignarCliente($personId, $preferido);
            }

            return $preferido;
        }

        return null;
    }

    /**
     * El responsable de lo que no tiene distribuidor (Huascar), o null si no está
     * configurado o no existe.
     */
    public function responsable(): ?int
    {
        $email = (string) config('distribuidor.responsable_email');

        if ($email === '') {
            return null;
        }

        $id = DB::table('users')->where('email', $email)->where('status', 1)->value('id');

        return $id ? (int) $id : null;
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

        $enSesion = auth()->guard('user')->id();
        $enSesion = $enSesion ? (int) $enSesion : null;
        $personId = $lead->person_id ? (int) $lead->person_id : null;

        $aMano = $actual && $enSesion && ! $this->esPedidoDeLaApi()
            && ! $this->distribuidores->esDistribuidor($enSesion);

        /*
         * Reasignación a mano: un encargado o administrador, desde el CRM, eligió a un
         * distribuidor de la ciudad como asesor del pedido. Gana sobre el dueño anterior
         * y se lleva al cliente entero —ficha, pedidos abiertos y conversación—, porque
         * un cliente no puede quedar repartido entre dos empresas.
         *
         * Cada update de un pedido vuelve a pasar por acá (lead.city.synced), así que
         * sin esto el reparto deshacía la elección del encargado en el mismo request.
         *
         * No vale para la API: el agente entra con el token de un administrador y el
         * user_id que mande no es una decisión de nadie del equipo.
         */
        if (
            $aMano
            && $this->distribuidores->esDistribuidorDe($actual, $pipelineId)
            && $actual !== $this->distribuidores->duenoDe($personId)
        ) {
            if ($personId) {
                $this->asignarCliente($personId, $actual);
            }

            return $actual;
        }

        // Sólo cuenta como "preferido" el distribuidor que carga el pedido él mismo.
        $preferido = $actual && $actual === $enSesion ? $actual : null;

        $dueno = $this->duenoPara($personId, $pipelineId, $preferido);

        if (! $dueno) {
            /*
             * Sin distribuidor: va al responsable (Huascar), que lo reparte. Así un pedido
             * nunca queda a nombre de un administrador o del token del agente, donde no lo
             * mira nadie. Sin responsable configurado, se deja como estaba.
             */
            $dueno = $this->responsable() ?? $actual;
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

        $this->moverPedidosAbiertos($personId, $userId);

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
     * Los clientes de un distribuidor dado de baja pasan al responsable.
     *
     * Un cliente a nombre de alguien que ya no entra al CRM no lo ve nadie. La ficha
     * queda sin dueño y sus pedidos abiertos van al responsable, que decide a qué
     * distribuidor dárselos.
     *
     * @return array{clientes: int, pedidos: int}
     */
    public function liberarClientesDe(int $userId): array
    {
        $resultado = ['clientes' => 0, 'pedidos' => 0];
        $responsable = $this->responsable();

        foreach (DB::table('persons')->where('user_id', $userId)->pluck('id') as $personId) {
            DB::table('persons')->where('id', $personId)->update(['user_id' => null]);
            $resultado['clientes']++;

            if ($responsable) {
                $resultado['pedidos'] += $this->moverPedidosAbiertos((int) $personId, $responsable);
            }
        }

        return $resultado;
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

    /** @return int Cuántos pedidos se movieron. */
    protected function moverPedidosAbiertos(int $personId, int $userId): int
    {
        $abiertos = DB::table('leads')
            ->where('person_id', $personId)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $userId))
            ->whereNotIn('lead_pipeline_stage_id', $this->etapasCerradas())
            ->pluck('id');

        if ($abiertos->isNotEmpty()) {
            DB::table('leads')->whereIn('id', $abiertos)->update(['user_id' => $userId]);
        }

        return $abiertos->count();
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
