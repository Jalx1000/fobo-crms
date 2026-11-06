<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Webkul\Admin\Support\VisibilidadLeads;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Core\Services\AlcanceCiudades;
use Webkul\Core\Services\AsignacionDeAsesor;
use Webkul\Distribuidor\Services\RegistroDeEntrega;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Services\CitySyncService;
use Webkul\Whatsapp\Services\PedidosDelChat;

/**
 * El pedido del cliente, gestionado sin salir del chat.
 *
 * Cada acción valida tres cosas: el permiso de quien la pide, que el pedido sea de su
 * alcance y que siga abierto. Un pedido cerrado es historia: se mira, no se mueve.
 */
class PedidoChatController extends Controller
{
    public function __construct(protected PedidosDelChat $pedidos) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'lead_id'         => 'nullable|integer',
        ]);

        return response()->json($this->pedidos->deContacto(
            (int) $id,
            isset($data['conversation_id']) ? (int) $data['conversation_id'] : null,
            isset($data['lead_id']) ? (int) $data['lead_id'] : null,
        ));
    }

    /**
     * Entrega desde el chat: lo mismo que el botón del Punto, foto y bidones
     * incluidos. Exige mover etapas y no el acceso al Punto, que es de los
     * distribuidores: el chat lo atienden también otros roles.
     */
    public function entregar(Request $request, string $id, RegistroDeEntrega $entregas): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('leads.stage_update'), 403, 'No tenés permiso para cambiar la etapa.');

        $data = $request->validate([
            'foto'               => 'nullable|image|max:8192',
            'bidones_entregados' => 'nullable|integer|min:0',
            'bidones_retirados'  => 'nullable|integer|min:0',
        ]);

        $lead = $this->pedidoAbierto($id);

        $entregas->registrar(
            (int) $lead->id,
            $lead->person_id ? (int) $lead->person_id : null,
            $request->file('foto'),
            (int) ($data['bidones_entregados'] ?? 0),
            (int) ($data['bidones_retirados'] ?? 0),
        );

        return response()->json(['message' => 'Entrega registrada.']);
    }

    /**
     * Cambia el asesor del cliente y, con él, sus pedidos abiertos y su derivación.
     *
     * Va por el dueño del CLIENTE porque un cliente no puede quedar repartido entre
     * dos asesores: cambiar sólo el pedido lo dejaría en manos del reparto, que lo
     * devolvería al dueño anterior. ClienteCambiaDeDistribuidor hace la mudanza.
     */
    public function asesor(Request $request, string $id): JsonResponse
    {
        $this->exigirAsignar();

        $data = $request->validate(['user_id' => 'required|integer']);

        $lead = $this->pedidoAbierto($id);

        $disponibles = array_column($this->pedidos->asesoresDe((int) $lead->lead_pipeline_id), 'id');

        if (! in_array((int) $data['user_id'], $disponibles, true)) {
            throw ValidationException::withMessages(['user_id' => 'Ese usuario no puede ser asesor de este pedido.']);
        }

        $person = $lead->person_id ? PersonProxy::modelClass()::find($lead->person_id) : null;

        if (! $person) {
            throw ValidationException::withMessages(['user_id' => 'El pedido no tiene un cliente.']);
        }

        $anterior = $person->user_id;

        Event::dispatch('contacts.person.update.before', $person->id);

        /*
         * Sobre el modelo y no por PersonRepository::update(): ese método normaliza la
         * ficha entera y espera el formulario completo, y acá sólo cambia el dueño.
         */
        $person->user_id = (int) $data['user_id'];
        $person->save();

        Event::dispatch('contacts.person.update.after', $person);

        Log::info('WhatsApp: asesor cambiado desde el chat', [
            'lead_id'   => $lead->id,
            'person_id' => $person->id,
            'antes'     => $anterior,
            'ahora'     => $person->user_id,
            'por'       => auth()->guard('user')->id(),
        ]);

        return response()->json(['message' => 'Asesor actualizado.']);
    }

    /**
     * Muda el pedido a otra ciudad. Sólo a una que quien lo pide atiende, y la etapa
     * pasa a su equivalente allá: cada ciudad tiene sus propios ids de etapa.
     *
     * Va por el repositorio porque es el que dispara todo lo demás: la ciudad del
     * cliente (CitySyncService) y el reparto del nuevo asesor.
     */
    public function ciudad(
        Request $request,
        string $id,
        LeadRepository $leads,
        CitySyncService $ciudades,
        AlcanceCiudades $alcance,
    ): JsonResponse {
        $this->exigirAsignar();

        $data = $request->validate(['lead_pipeline_id' => 'required|integer']);

        $lead = $this->pedidoAbierto($id);

        $destino = (int) $data['lead_pipeline_id'];

        if (! in_array($destino, array_column($alcance->ciudades(), 'id'), true)) {
            throw ValidationException::withMessages(['lead_pipeline_id' => 'No atendés esa ciudad.']);
        }

        if ($destino === (int) $lead->lead_pipeline_id) {
            return response()->json(['message' => 'El pedido ya está en esa ciudad.']);
        }

        $etapa = $ciudades->mapStageToPipeline((int) $lead->lead_pipeline_stage_id, $destino);

        Event::dispatch('lead.update.before', $lead->id);

        $lead = $leads->update([
            'entity_type'            => 'leads',
            'lead_pipeline_id'       => $destino,
            'lead_pipeline_stage_id' => $etapa,
        ], $lead->id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $lead);

        return response()->json(['message' => 'Ciudad actualizada.']);
    }

    protected function exigirAsignar(): void
    {
        abort_unless(
            app(AsignacionDeAsesor::class)->puede(),
            403,
            'Sólo los administradores y los encargados habilitados pueden asignar.',
        );
    }

    protected function pedidoAbierto(string $id)
    {
        $lead = LeadProxy::modelClass()::with('stage')->findOrFail($id);

        abort_unless(VisibilidadLeads::puedeVer($lead), 403, 'Ese pedido no está en tu alcance.');

        if (! $this->pedidos->abierto($lead)) {
            abort(422, 'Ese pedido ya está cerrado.');
        }

        return $lead;
    }
}
