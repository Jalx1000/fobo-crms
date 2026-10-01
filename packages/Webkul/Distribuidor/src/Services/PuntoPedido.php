<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Repositories\LeadRepository;

/**
 * El ciclo de un pedido tomado en el Punto.
 *
 * Todo pasa por `LeadRepository`, no por Eloquent directo: ahí viven los eventos
 * (`lead.create.after`), la sincronización de ciudad y la deduplicación de
 * contactos por teléfono. Escribir el lead a mano desde acá se saltearía las
 * tres y el pedido quedaría fuera de los tableros.
 */
class PuntoPedido
{
    public function __construct(
        protected LeadRepository $leads,
        protected PersonRepository $personas,
    ) {}

    /**
     * El contacto detrás de un teléfono: el que ya existe, o uno nuevo.
     *
     * Reusa `findByPhone` del CRM, que compara por sufijo normalizado. Es lo que
     * evita el duplicado clásico: el mismo cliente cargado como "76616013" y
     * como "+591 76616013" son dos fichas con el historial partido.
     */
    public function contacto(string $telefono, ?string $nombre = null): array
    {
        if ($persona = $this->personas->findByPhone($telefono)) {
            return ['persona' => $persona, 'creada' => false];
        }

        $persona = $this->personas->create([
            'name'            => $nombre ?: 'Cliente '.$telefono,
            'entity_type'     => 'persons',
            'contact_numbers' => [['label' => 'work', 'value' => $telefono]],
        ]);

        return ['persona' => $persona, 'creada' => true];
    }

    /**
     * Abre el pedido.
     *
     * Entra en la etapa inicial de la ciudad elegida. El Punto NO pregunta canal
     * ni tipo de cliente —es una venta en mano, no un formulario— pero el CRM los
     * exige, así que salen de config.
     *
     * @param  array<int, array{product_id:int, quantity:float, price:float}>  $productos
     */
    public function crear(int $personId, int $ciudadId, array $productos, ?string $nota = null)
    {
        $etapa = $this->etapaDe($ciudadId, config('distribuidor.etapas.inicial'));

        $persona = $this->personas->find($personId);

        $total = collect($productos)->sum(fn ($p) => (float) $p['price'] * (float) $p['quantity']);

        return $this->leads->create([
            /*
             * `LeadRepository::create` le pasa este mismo array a
             * `AttributeValueRepository::save()`, que lee `entity_type` para saber
             * qué atributos custom guardar. Sin la clave explota con
             * "Undefined array key entity_type" DESPUÉS de haber creado el lead.
             * El LeadController del admin la inyecta igual (línea 58).
             */
            'entity_type'            => 'leads',
            'title'                  => $persona?->name ?: 'Pedido del Punto',
            'description'            => $nota,
            'lead_value'             => $total,
            'person_id'              => $personId,
            'lead_pipeline_id'       => $ciudadId,
            'lead_pipeline_stage_id' => $etapa,
            'lead_source_id'         => config('distribuidor.canal_por_defecto'),
            'lead_type_id'           => $this->tipoDeCliente($personId),
            'user_id'                => auth()->guard('user')->id(),
            'status'                 => 1,
            'products'               => $productos,
        ]);
    }

    /**
     * Mueve el pedido a otra etapa, dentro de su propia ciudad.
     *
     * La etapa se resuelve por CÓDIGO y no por id: cada ciudad tiene su propio
     * juego de etapas con los mismos códigos, así que un id de otra ciudad
     * movería el pedido de pipeline sin que nadie lo pida.
     */
    public function avanzar(int $leadId, string $codigoEtapa)
    {
        $lead = LeadProxy::modelClass()::findOrFail($leadId);

        $etapa = $this->etapaDe((int) $lead->lead_pipeline_id, $codigoEtapa);

        abort_if($etapa === null, 422, 'Esa etapa no existe en la ciudad del pedido.');

        /*
         * `entity_type` por el mismo motivo que en crear(): LeadRepository le pasa
         * este array a AttributeValueRepository::save(), que lo lee para saber qué
         * atributos custom tocar. Sin la clave explota DESPUÉS de haber movido la
         * etapa, dejando el pedido avanzado y la respuesta en error.
         */
        return $this->leads->update([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $etapa,
        ], $leadId);
    }

    /**
     * ¿Cliente nuevo o antiguo? Se deduce, no se pregunta.
     *
     * El Punto fijaba siempre "Nuevo Cliente", así que la segunda compra del
     * mismo cliente volvía a contarlo como nuevo y cualquier reporte de nuevos
     * contra recurrentes salía inservible. El distribuidor tampoco lo sabe —puede
     * ser la primera vez que lo atiende él— pero el CRM sí: alcanza con mirar si
     * la persona ya tiene un pedido.
     */
    protected function tipoDeCliente(int $personId): int
    {
        $tieneAnteriores = DB::table('leads')->where('person_id', $personId)->exists();

        return (int) config(
            $tieneAnteriores ? 'distribuidor.tipo_antiguo' : 'distribuidor.tipo_nuevo'
        );
    }

    /**
     * El id de una etapa dentro de un pipeline, por código.
     */
    public function etapaDe(int $pipelineId, string $codigo): ?int
    {
        $id = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->where('code', $codigo)
            ->value('id');

        return $id ? (int) $id : null;
    }
}
