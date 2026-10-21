<?php

namespace Webkul\Inmobiliaria\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\Inmobiliaria\Services\SinInteres;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Models\PipelineProxy;
use Webkul\Lead\Models\SourceProxy;
use Webkul\Lead\Models\TypeProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Alta de leads desde la conversación del agente.
 *
 * Los tres flujos —comprador, captación y postventa— viven en el MISMO pipeline
 * (`Inmobiliaria C21`) y se distinguen por `lead_type`. Es una decisión de C21
 * que contradice el brief original del agente, que pedía tres pipelines
 * separados: acá el tablero es uno solo y el tipo es la etiqueta.
 */
class LeadController extends Controller
{
    /*
     * Cómo se nombra cada flujo cuando el agente le repite la referencia al
     * cliente. El brief solo definía "Solicitud #<id>" para el comprador;
     * llamar "solicitud" a una captación confunde al propietario, que no pidió
     * nada sino que ofreció algo.
     */
    protected const REFERENCIAS = [
        'solicitud' => 'Solicitud',
        'alquiler'  => 'Solicitud',
        'captacion' => 'Captación',
        'postventa' => 'Postventa',
    ];

    /** Qué tipo de lead corresponde a cada flujo. */
    protected const TIPOS = [
        'solicitud' => 'Comprador',
        'alquiler'  => 'Inquilino',
        'captacion' => 'Propietario',
        'postventa' => 'Postventa',
    ];

    public function __construct(
        protected LeadRepository $leads,
        protected PersonRepository $personas,
        protected InmuebleRepository $inmuebles,
        protected SinInteres $sinInteres,
    ) {}

    /**
     * Lead de comprador. Se crea una sola vez, cuando el cliente confirmó.
     */
    public function solicitud(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'nombre'            => 'required|string|max:120',
            'operacion'         => 'nullable|in:venta,alquiler,anticretico',
            'tipo'              => 'nullable|string|max:40',
            'zonas'             => 'nullable|array',
            'presupuesto'       => 'nullable|numeric|min:0',
            'moneda'            => 'nullable|in:USD,BOB',
            'dormitorios_min'   => 'nullable|integer|min:0',
            'temperatura'       => 'nullable|in:caliente,tibio,frio',
            'codigos_interes'   => 'nullable|array',
            'codigos_interes.*' => 'string|max:32',
            'notas'             => 'nullable|string',
        ]);

        // Un alquiler es un inquilino, no un comprador: el mismo endpoint sirve
        // para los dos porque para el cliente es la misma conversación.
        $flujo = ($datos['operacion'] ?? 'venta') === 'alquiler' ? 'alquiler' : 'solicitud';

        $titulo = $this->titulo($datos, 'Busca');

        return $this->crear(
            $id, $flujo, $datos['nombre'], $titulo, $this->detalle($datos),
            $datos['presupuesto'] ?? 0, $datos['codigos_interes'] ?? [],
        );
    }

    /**
     * Propietario que quiere listar su inmueble con la oficina.
     */
    public function captacion(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'nombre'      => 'required|string|max:120',
            'tipo'        => 'nullable|string|max:40',
            'zona'        => 'nullable|string|max:120',
            'operacion'   => 'nullable|in:venta,alquiler,anticretico',
            'precio'      => 'nullable|numeric|min:0',
            'moneda'      => 'nullable|in:USD,BOB',
            'notas'       => 'nullable|string',
        ]);

        $titulo = 'Captación — '.trim(($datos['tipo'] ?? 'Inmueble').' en '.($datos['zona'] ?? 'zona a definir'));

        return $this->crear($id, 'captacion', $datos['nombre'], $titulo, $this->detalle($datos), $datos['precio'] ?? 0);
    }

    /**
     * El cliente no tiene interés o no califica (otra ciudad, otra zona, fuera
     * de presupuesto, «ya no me interesa»).
     *
     * Mueve a «Sin interés» los leads abiertos del contacto, con el motivo en
     * su timeline. Si la conversación todavía no tiene lead, no hay nada que
     * mover y responde igual 200: para el agente el resultado es el mismo.
     */
    public function sinInteres(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'motivo' => 'required|string|max:500',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $movidos = [];

        if ($conversation->person_id) {
            $leads = LeadProxy::modelClass()::with(['stage', 'pipeline'])
                ->where('person_id', $conversation->person_id)
                ->get();

            foreach ($leads as $lead) {
                if ($this->sinInteres->mover($lead, $datos['motivo'])) {
                    $movidos[] = $lead->id;
                }
            }
        }

        return response()->json([
            'message' => $movidos ? 'Lead movido a Sin interés.' : 'No había leads abiertos para mover.',
            'leads'   => $movidos,
        ]);
    }

    /**
     * Cliente con una operación ya en curso.
     */
    public function postventa(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'nombre'            => 'required|string|max:120',
            'detalle'           => 'required|string',
            'codigo_o_contrato' => 'nullable|string|max:60',
        ]);

        $titulo = 'Postventa'.(filled($datos['codigo_o_contrato'] ?? null) ? " — {$datos['codigo_o_contrato']}" : '');

        return $this->crear($id, 'postventa', $datos['nombre'], $titulo, $datos['detalle'], 0);
    }

    /**
     * Crea el lead, la persona si hacía falta, y los deja vinculados a la
     * conversación para que el agente pueda leer el historial después.
     */
    /**
     * @param  array<int, string>  $codigosInteres
     */
    protected function crear(string $conversationId, string $flujo, string $nombre, string $titulo, string $detalle, float $valor, array $codigosInteres = []): JsonResponse
    {
        $conversation = ConversationProxy::modelClass()::findOrFail($conversationId);

        $pipeline = PipelineProxy::modelClass()::where('name', config('inmobiliaria.inmuebles.pipeline'))->first()
            ?? PipelineProxy::modelClass()::where('is_default', 1)->first()
            ?? PipelineProxy::modelClass()::firstOrFail();

        $etapa = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

        $tipo = TypeProxy::modelClass()::where('name', self::TIPOS[$flujo])->first();

        if (! $tipo) {
            // Explícito en vez de caer en el primer tipo que haya: un lead con
            // el tipo equivocado se ve bien y desvía la gestión.
            return response()->json([
                'message' => "Falta el tipo de lead '".self::TIPOS[$flujo]."'. Agregalo en Configuración › Tipos.",
            ], 409);
        }

        $actualizado = false;

        $lead = DB::transaction(function () use ($conversation, $nombre, $titulo, $detalle, $valor, $pipeline, $etapa, $tipo, $codigosInteres, &$actualizado) {
            // Bloquea la conversación: dos llamadas casi simultáneas del agente
            // se ejecutan una detrás de otra, y la segunda ya encuentra el lead
            // que creó la primera.
            $conversation = ConversationProxy::modelClass()::whereKey($conversation->getKey())->lockForUpdate()->first();

            $persona = $this->persona($conversation, $nombre);

            /*
             * UN LEAD ABIERTO POR CONTACTO Y TIPO.
             *
             * El agente llama a este endpoint más de una vez en la misma
             * conversación: primero con lo poco que sabe («Busca inmueble») y
             * de nuevo cuando junta más datos («Busca departamento hasta USD
             * 150.000»). Crear uno por llamada dejaba dos y tres leads por
             * cliente en el kanban. Si ya hay uno abierto del mismo tipo, se
             * actualiza ese con lo último que juntó el agente.
             */
            if ($abierto = $this->leadAbierto($persona->id, $tipo->id, $conversation->lead_id)) {
                // Estaba en «Sin interés» y el cliente volvió: se reactiva el
                // mismo lead en vez de abrir otro.
                $this->sinInteres->reactivar($abierto->loadMissing(['stage', 'pipeline']));
                $abierto->refresh();

                $abierto->forceFill(array_filter([
                    'title'       => $titulo,
                    'description' => $detalle,
                    // Un presupuesto en 0 es «no lo dijo», no «bajó a cero».
                    'lead_value'  => $valor > 0 ? $valor : null,
                ], fn ($v) => $v !== null))->save();

                Event::dispatch('lead.update.after', $abierto);

                if ($codigosInteres) {
                    $this->inmuebles->vincularCodigos($abierto->id, $codigosInteres);
                }

                $conversation->forceFill([
                    'person_id' => $persona->id,
                    'lead_id'   => $conversation->lead_id ?? $abierto->id,
                ])->save();

                $actualizado = true;

                return $abierto;
            }

            Event::dispatch('lead.create.before');

            $lead = $this->leads->create([
                'entity_type'            => 'leads',
                'title'                  => $titulo,
                'description'            => $detalle,
                'lead_value'             => $valor,
                'person'                 => ['id' => $persona->id],
                'lead_pipeline_id'       => $pipeline->id,
                'lead_pipeline_stage_id' => $etapa->id,
                'lead_source_id'         => SourceProxy::modelClass()::firstOrCreate(['name' => 'WhatsApp orgánico'])->id,
                'lead_type_id'           => $tipo->id,
                'status'                 => 1,
            ]);

            Event::dispatch('lead.create.after', $lead);

            // El interés queda como vínculo, no solo como texto en la
            // descripción: así el asesor lo ve en la ficha y se puede saber qué
            // inmuebles generan consultas.
            if ($codigosInteres) {
                $this->inmuebles->vincularCodigos($lead->id, $codigosInteres);
            }

            // El primer lead de la conversación queda como el lead "de" la
            // conversación; los siguientes no lo pisan.
            $conversation->forceFill([
                'person_id' => $persona->id,
                'lead_id'   => $conversation->lead_id ?? $lead->id,
            ])->save();

            return $lead;
        });

        return response()->json([
            'message'    => $actualizado ? 'Lead actualizado.' : 'Lead creado.',
            'actualizado' => $actualizado,
            'lead_id'    => $lead->id,
            'referencia' => self::REFERENCIAS[$flujo]." #{$lead->id}",
        ]);
    }

    /**
     * El lead abierto de este contacto para este tipo, si hay uno.
     *
     * «Abierto» = no está en una etapa de cierre (ganado o perdido, según
     * `reporting.stages`). Si el cliente ya cerró o desistió y vuelve a
     * escribir, corresponde un lead nuevo. Prefiere el lead vinculado a la
     * conversación; si no, el más reciente.
     */
    protected function leadAbierto(int $personaId, int $tipoId, ?int $leadDeLaConversacion)
    {
        $cerradas = DB::table('lead_pipeline_stages')
            ->whereIn('code', array_merge(
                (array) config('reporting.stages.won', []),
                (array) config('reporting.stages.lost', []),
            ))
            ->pluck('id');

        return LeadProxy::modelClass()::query()
            ->where('person_id', $personaId)
            ->where('lead_type_id', $tipoId)
            ->whereNotIn('lead_pipeline_stage_id', $cerradas)
            ->orderByRaw('id = ? desc', [(int) $leadDeLaConversacion])
            ->latest('id')
            ->first();
    }

    /**
     * La persona de la conversación, creándola si es la primera vez.
     */
    protected function persona($conversation, string $nombre)
    {
        if ($conversation->person_id && $persona = $this->personas->find($conversation->person_id)) {
            return $persona;
        }

        return $this->personas->create([
            'name'            => $nombre,
            'emails'          => [],
            'contact_numbers' => filled($conversation->wa_phone)
                ? [['value' => $conversation->wa_phone, 'label' => 'work']]
                : [],
        ]);
    }

    /**
     * Título legible para el kanban del asesor.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function titulo(array $datos, string $prefijo): string
    {
        $partes = array_filter([
            $datos['tipo'] ?? null,
            filled($datos['zonas'] ?? null) ? 'en '.implode(', ', (array) $datos['zonas']) : null,
            filled($datos['presupuesto'] ?? null)
                ? 'hasta '.($datos['moneda'] ?? 'USD').' '.number_format((float) $datos['presupuesto'], 0, ',', '.')
                : null,
        ]);

        return trim($prefijo.' '.(empty($partes) ? 'inmueble' : implode(' ', $partes)));
    }

    /**
     * Todo lo que el agente recolectó, en la descripción del lead.
     *
     * Va como texto y no como atributos porque el asesor lo lee de un vistazo, y
     * porque `temperatura` y `codigos_interes` son contexto de la conversación,
     * no campos maestros del CRM.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function detalle(array $datos): string
    {
        $etiquetas = [
            'operacion'          => 'Operación', 'tipo' => 'Tipo', 'zonas' => 'Zonas', 'zona' => 'Zona',
            'presupuesto'        => 'Presupuesto', 'precio' => 'Precio pretendido', 'moneda' => 'Moneda',
            'dormitorios_min'    => 'Dormitorios mínimos', 'temperatura' => 'Temperatura',
            'codigos_interes'    => 'Inmuebles consultados',
            'visita_preferencia' => 'Disponibilidad que indicó el cliente',
            'notas'              => 'Notas',
        ];

        $lineas = [];

        foreach ($etiquetas as $clave => $etiqueta) {
            if (blank($datos[$clave] ?? null)) {
                continue;
            }

            $valor = is_array($datos[$clave]) ? implode(', ', $datos[$clave]) : $datos[$clave];
            $lineas[] = "{$etiqueta}: {$valor}";
        }

        return implode("\n", $lineas) ?: 'Sin detalle.';
    }
}
