<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\VisibilidadLeads;
use Webkul\Core\Services\AlcanceCiudades;
use Webkul\Core\Services\AsignacionDeAsesor;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Services\CitySyncService;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Lo que necesita el selector de pedido del chat: los pedidos del cliente que el
 * usuario puede ver, cuál mostrar y qué puede hacer con ellos.
 *
 * "Abierto" sale de la etapa (CLOSED_STAGE_CODES) y no de `leads.status`: nada pone
 * ese campo en 0 al entregar o cancelar, así que un pedido cerrado sigue en 1.
 */
class PedidosDelChat
{
    /** Los pedidos que se ofrecen: alcanza de sobra para elegir el de este chat. */
    protected const LIMITE = 20;

    /** @var array<int, array<int, array{id:int, name:string}>> */
    protected array $asesoresPorCiudad = [];

    public function __construct(
        protected AlcanceCiudades $alcance,
        protected AsignacionDeAsesor $asignacion,
    ) {}

    /**
     * @param  int|null  $preferido  El pedido en pantalla (la ficha del pedido).
     */
    public function deContacto(int $personId, ?int $conversationId = null, ?int $preferido = null): array
    {
        $puedeAsignar = $this->asignacion->puede();

        $query = LeadProxy::modelClass()::query()
            ->with(['stage', 'pipeline.stages', 'user'])
            ->where('leads.person_id', $personId);

        VisibilidadLeads::aplicar($query);

        $leads = $query->orderByDesc('leads.id')->limit(self::LIMITE)->get();

        $pedidos = $leads
            ->map(fn ($lead) => $this->presentar($lead, $puedeAsignar))
            // Estable: dentro de abiertos y cerrados sigue mandando el más nuevo.
            ->sortByDesc(fn ($pedido) => $pedido['abierto'] ? 1 : 0)
            ->values();

        return [
            'pedidos'      => $pedidos,
            'seleccionado' => $this->seleccionado($pedidos->all(), $personId, $conversationId, $preferido),
            'puede'        => [
                'etapa'   => bouncer()->hasPermission('leads.stage_update'),
                'asignar' => $puedeAsignar,
            ],
            'ciudades'     => $puedeAsignar ? $this->alcance->ciudades() : [],
        ];
    }

    /**
     * Un pedido listo para el selector.
     */
    public function presentar($lead, bool $conAsesores = false): array
    {
        $entregado = config('distribuidor.etapas.entregado');
        $cancelado = config('distribuidor.etapas.cancelado');

        return [
            'id'      => (int) $lead->id,
            'title'   => $lead->title,
            'abierto' => $this->abierto($lead),
            'etapa'   => [
                'id'   => (int) $lead->lead_pipeline_stage_id,
                'name' => $lead->stage->name ?? null,
            ],
            'etapas'  => collect($lead->pipeline?->stages ?? [])
                ->sortBy('sort_order')
                ->map(fn ($etapa) => [
                    'id'   => (int) $etapa->id,
                    'name' => $etapa->name,
                    // El chat abre un diálogo para estas dos: no son un clic y listo.
                    'tipo' => match ($etapa->code) {
                        $entregado => 'entregado',
                        $cancelado => 'cancelado',
                        default    => null,
                    },
                ])
                ->values(),
            'ciudad'  => [
                'id'   => (int) $lead->lead_pipeline_id,
                'name' => $lead->pipeline->name ?? null,
            ],
            'asesor'  => [
                'id'   => $lead->user_id ? (int) $lead->user_id : null,
                'name' => $lead->user->name ?? null,
            ],
            'asesores' => $conAsesores ? $this->asesoresDe((int) $lead->lead_pipeline_id) : [],
            'valor'    => $lead->lead_value ? number_format((float) $lead->lead_value, 2) : null,
            'fecha'    => optional($lead->created_at)->format('d/m/Y'),
            'url'      => route('admin.leads.view', $lead->id),
        ];
    }

    public function abierto($lead): bool
    {
        return ! in_array($lead->stage?->code, CitySyncService::CLOSED_STAGE_CODES, true);
    }

    /**
     * A quién se le puede dar un pedido: los mismos roles que "Asesor Asignado", así
     * que todos los distribuidores activos, atiendan o no esa ciudad. Un encargado
     * sólo si ve la ciudad: si no, el pedido quedaría a nombre de alguien que no lo
     * encuentra. Administradores no: no atienden pedidos.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function asesoresDe(int $pipelineId): array
    {
        return $this->asesoresPorCiudad[$pipelineId] ??= DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.status', 1)
            ->whereIn('roles.name', (array) config('attribute_lookups.distribuidores.role'))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'roles.name as rol'])
            ->filter(fn ($usuario) => $usuario->rol !== AsignacionDeAsesor::ROL_ENCARGADO
                || $this->alcance->puedeVer($pipelineId, (int) $usuario->id))
            ->map(fn ($usuario) => ['id' => (int) $usuario->id, 'name' => (string) $usuario->name])
            ->values()
            ->all();
    }

    /**
     * El que está en pantalla, el vinculado a la conversación si sigue abierto, el
     * abierto más nuevo o, si todos cerraron, el último.
     */
    protected function seleccionado(array $pedidos, int $personId, ?int $conversationId, ?int $preferido): ?int
    {
        $ids = array_column($pedidos, 'id');

        if ($preferido && in_array($preferido, $ids, true)) {
            return $preferido;
        }

        $vinculado = $conversationId
            ? ConversationProxy::modelClass()::whereKey($conversationId)->where('person_id', $personId)->value('lead_id')
            : null;

        $abiertos = array_column(array_filter($pedidos, fn ($pedido) => $pedido['abierto']), 'id');

        if ($vinculado && in_array((int) $vinculado, $abiertos, true)) {
            return (int) $vinculado;
        }

        return $abiertos[0] ?? $ids[0] ?? null;
    }
}
