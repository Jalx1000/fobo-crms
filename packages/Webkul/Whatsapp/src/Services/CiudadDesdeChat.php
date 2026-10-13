<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\CiudadCliente;
use Webkul\Product\Services\Responsables;
use Webkul\Product\Support\ListaPrecios;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

/**
 * La ciudad que el cliente contesta en el chat: se guarda y decide el asesor.
 *
 * Caso real (13/10): todo se derivaba a Mariano. El contacto y el pedido nacen
 * a nombre del asesor por defecto (todavía no hay ciudad) y la respuesta al
 * menú «¿De qué ciudad nos escribe?» no se guardaba: 139 conversaciones la
 * dijeron y 42 contactos la tenían. Al derivar, el dueño ya era el asesor por
 * defecto y ahí se quedaba.
 *
 * Solo se toma como ciudad la respuesta a una pregunta por la ciudad, para no
 * confundir «quiero enviar a Sucre» con la ciudad del cliente.
 */
class CiudadDesdeChat
{
    public function __construct(
        protected CiudadCliente $ciudades,
        protected Responsables $responsables,
    ) {}

    public function revisar(Message $message): void
    {
        try {
            $this->procesar($message);
        } catch (\Throwable $e) {
            // Nunca romper la recepción de un mensaje por esto.
            Log::warning('WhatsApp: no se pudo tomar la ciudad del chat.', [
                'message' => $message->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function procesar(Message $message): void
    {
        $conversation = $message->conversation;

        if ($message->direction !== 'inbound' || ! $conversation?->person_id || trim((string) $message->body) === '') {
            return;
        }

        $pregunta = $conversation->messages()
            ->where('direction', 'outbound')
            ->where('id', '<', $message->id)
            ->latest('id')
            ->value('body');

        if (! $pregunta || ! str_contains(mb_strtolower($pregunta), 'ciudad')) {
            return;
        }

        $ciudad = $this->ciudades->desdeTexto((string) $message->body);

        if (! $ciudad) {
            return;
        }

        $this->ciudades->escribir('persons', $conversation->person_id, $ciudad);
        $this->ciudades->propagar($conversation->person_id, $ciudad);

        $this->reasignar($conversation, $ciudad);
        $this->moverDePipeline($conversation, $ciudad);
    }

    /**
     * Si el pedido sigue a nombre del asesor por defecto (o de nadie que
     * venda), pasa a quien atiende la ciudad. Un pedido que ya es de otro
     * asesor no se toca.
     */
    protected function reasignar(Conversation $conversation, string $ciudad): void
    {
        $defecto = $this->responsables->asesorPorDefecto()?->id;

        $provisorio = fn ($userId) => ! $userId
            || (int) $userId === (int) $defecto
            || ! $this->responsables->esAsesor((int) $userId);

        $lead = $conversation->lead_id ? DB::table('leads')->find($conversation->lead_id) : null;
        $personaUser = DB::table('persons')->where('id', $conversation->person_id)->value('user_id');

        if (($lead && ! $provisorio($lead->user_id)) || (! $lead && ! $provisorio($personaUser))) {
            return;
        }

        // Con el producto, la matriz producto × ciudad (local primero, si no
        // la cobertura nacional); sin producto, quien atiende todo en la ciudad.
        $producto = $this->producto($conversation);

        $nuevo = $producto
            ? $this->responsables->siguiente($producto, $ciudad)
            : $this->responsables->porCiudad($ciudad);

        if (! $nuevo || ($lead && (int) $nuevo->id === (int) $lead->user_id)) {
            return;
        }

        if ($lead) {
            DB::table('leads')->where('id', $lead->id)->update(['user_id' => $nuevo->id]);

            \Webkul\Product\Services\Responsables::cotizacionesConElPedido((int) $lead->id);
        }

        if ($provisorio($personaUser)) {
            DB::table('persons')->where('id', $conversation->person_id)->update(['user_id' => $nuevo->id]);
        }

        if ($conversation->handoffOpen() && $provisorio($conversation->assigned_user_id)) {
            $conversation->forceFill([
                'assigned_user_id' => $nuevo->id,
                'assigned_at'      => now(),
                'handoff_state'    => Conversation::HANDOFF_ASSIGNED,
            ])->save();
        }
    }

    /**
     * Palabras con las que el cliente nombra cada producto (por SKU): los
     * títulos del menú («🟢 Bolsas Magia Verde») y lo que traen los anuncios
     * («¿Cuánto cuesta la bolsa XXL?»).
     */
    protected const PALABRAS_PRODUCTO = [
        'CM_00001' => ['stretch', 'strech', 'film', 'rollo', 'manual', 'automatico'],
        'CM_00002' => ['magiaverde', 'bolsa'],
        'CM_00003' => ['hule'],
        'CM_00004' => ['tapa'],
    ];

    /**
     * El producto del que habla el cliente: el del pedido si lo tiene, si no
     * el último que nombró en el chat.
     */
    public function producto(Conversation $conversation): ?\Webkul\Product\Models\Product
    {
        $modelo = \Webkul\Product\Models\ProductProxy::modelClass();

        if ($conversation->lead_id
            && $id = DB::table('lead_products')->where('lead_id', $conversation->lead_id)->orderByDesc('id')->value('product_id')) {
            return $modelo::find($id);
        }

        $textos = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('id')
            ->limit(30)
            ->pluck('body');

        foreach ($textos as $texto) {
            $limpio = ListaPrecios::comparable((string) $texto);

            foreach (self::PALABRAS_PRODUCTO as $sku => $palabras) {
                foreach ($palabras as $palabra) {
                    if ($limpio !== '' && str_contains($limpio, $palabra)) {
                        return $modelo::where('sku', $sku)->first();
                    }
                }
            }
        }

        return null;
    }

    /**
     * Cada ciudad tiene su pipeline. Un pedido que quedó en el pipeline por
     * defecto o en «Sin ciudad» pasa al de su ciudad, en la misma etapa. Si un
     * asesor ya lo puso en otro pipeline, no se mueve.
     */
    protected function moverDePipeline(Conversation $conversation, string $ciudad): void
    {
        $lead = $conversation->lead_id ? DB::table('leads')->find($conversation->lead_id) : null;

        if (! $lead) {
            return;
        }

        $pipelines = DB::table('lead_pipelines')->get(['id', 'name', 'is_default']);

        $destino = $pipelines->first(fn ($p) => ListaPrecios::comparable($p->name) === ListaPrecios::comparable($ciudad));
        $actual = $pipelines->firstWhere('id', $lead->lead_pipeline_id);

        $movible = $actual && ($actual->is_default || ListaPrecios::comparable($actual->name) === ListaPrecios::comparable('Sin ciudad'));

        if (! $destino || ! $movible || (int) $destino->id === (int) $lead->lead_pipeline_id) {
            return;
        }

        $codigo = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code');

        $etapa = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $destino->id)->where('code', $codigo)->value('id')
            ?? DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $destino->id)->orderBy('sort_order')->value('id');

        if ($etapa) {
            DB::table('leads')->where('id', $lead->id)->update([
                'lead_pipeline_id'       => $destino->id,
                'lead_pipeline_stage_id' => $etapa,
            ]);
        }
    }
}
