<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Lead\Models\PipelineProxy;
use Webkul\Lead\Models\SourceProxy;
use Webkul\Lead\Models\TypeProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\UserProxy;

/**
 * Abre el pedido de un contacto que escribió por WhatsApp.
 *
 * Sin esto, quien escribe queda registrado como contacto pero NO aparece en
 * ningún pipeline: 156 de 168 conversaciones estaban así. Para el equipo esas
 * personas simplemente no existían — nadie las ve, nadie las sigue, y no entran
 * en ninguna métrica.
 *
 * EN KOHLBERG LA CIUDAD ES EL PIPELINE, así que el pedido se abre en la ciudad
 * del contacto y a nombre de su encargado. Cuando la ciudad no se conoce cae en
 * el pipeline por defecto (Tarija) con su encargado, que es donde el equipo ya
 * mira lo que llega sin clasificar.
 */
class LeadOpener
{
    public function __construct(protected CityRouter $cities) {}

    /**
     * El pedido al que apunta una conversación: el último del contacto, o uno
     * nuevo si no tiene ninguno.
     *
     * Devuelve null si está apagado por config o si algo falla — un pedido que
     * no se pudo abrir NUNCA puede costar el mensaje del cliente.
     */
    public function forPerson(?Model $person): ?int
    {
        if (! $person) {
            return null;
        }

        // Ya tiene pedidos: se enlaza el más reciente en vez de abrir otro.
        if ($existing = $person->leads()->latest()->first()) {
            return $existing->id;
        }

        if (! config('whatsapp.auto_create_lead', true)) {
            return null;
        }

        return optional($this->open($person))->id;
    }

    /**
     * Abre el pedido. Espeja la creación rápida del panel del cliente para que
     * los dos caminos dejen pedidos idénticos.
     */
    public function open(Model $person, ?string $title = null, float $value = 0): ?Model
    {
        try {
            $pipeline = $this->pipelineFor($person);

            if (! $pipeline) {
                return null;
            }

            $stage = $pipeline->stages()->orderBy('sort_order')->first();

            if (! $stage) {
                return null;
            }

            /*
             * 'Whatsapp', no 'WhatsApp': así se llama la fuente que ya existe y
             * tiene 537 pedidos. firstOrCreate con otra grafía crearía una
             * segunda fuente y partiría el reporte en dos.
             */
            $source = SourceProxy::modelClass()::firstOrCreate(['name' => 'Whatsapp']);
            $type = TypeProxy::modelClass()::first();

            Event::dispatch('lead.create.before');

            $lead = app(LeadRepository::class)->create([
                'entity_type'            => 'leads',
                'title'                  => $title ?: 'WhatsApp - '.($person->name ?: 'Contacto'),
                'lead_value'             => $value,
                'person'                 => ['id' => $person->id],
                'lead_pipeline_id'       => $pipeline->id,
                'lead_pipeline_stage_id' => $stage->id,
                'lead_source_id'         => $source->id,
                'lead_type_id'           => optional($type)->id,
                // El encargado de esa ciudad, o el revisor de lo sin clasificar.
                'user_id'                => $this->ownerFor($pipeline, $person),
                'status'                 => 1,
            ]);

            Event::dispatch('lead.create.after', $lead);

            Log::info('WhatsApp: pedido abierto automáticamente', [
                'lead_id'   => $lead->id,
                'person_id' => $person->id,
                'pipeline'  => $pipeline->name,
            ]);

            return $lead;
        } catch (Throwable $e) {
            /*
             * Se traga la excepción a propósito: esto corre dentro de la
             * ingesta del webhook. Si abrir el pedido falla, el mensaje del
             * cliente tiene que guardarse igual — perderlo es mucho peor que
             * quedarse sin pedido, que un humano puede crear después.
             */
            Log::warning('WhatsApp: no se pudo abrir el pedido', [
                'person_id' => $person->id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * La ciudad del contacto como pipeline, o el pipeline por defecto.
     */
    public function pipelineFor(Model $person): ?Model
    {
        $model = PipelineProxy::modelClass();

        $cityId = $this->cities->cityOfPerson((int) $person->id);

        if ($cityId && $pipeline = $model::find($cityId)) {
            return $pipeline;
        }

        /*
         * Ciudad desconocida: al pipeline "Sin ciudad", NO al por defecto.
         *
         * CityRouter toma "el pipeline del lead" como la fuente más fuerte de la
         * ciudad, por encima de lo que el cliente le diga al agente. Rellenar
         * con Tarija dejaría a un cliente de Santa Cruz marcado como de Tarija
         * sin que su propia respuesta pudiera corregirlo. "Sin ciudad" está
         * excluido de esa cascada, así que el pedido existe sin inventar nada.
         */
        $nombre = (string) config('whatsapp.unknown_city_pipeline', 'Sin ciudad');

        return $model::where('name', $nombre)->first()
            ?? $model::where('is_default', 1)->first()
            ?? $model::first();
    }

    /**
     * El dueño del pedido: el encargado de la ciudad, o el responsable de
     * revisar lo que llega sin clasificar.
     *
     * Sin esto los pedidos sin ciudad quedan sin dueño y no le figuran a nadie
     * como propios — existen en el tablero pero nadie los mira.
     */
    public function ownerFor(Model $pipeline, Model $person): ?int
    {
        if ($asesor = $this->cities->advisorFor($pipeline->id)) {
            return $asesor;
        }

        $nombre = trim((string) config('whatsapp.unknown_city_owner', ''));

        if ($nombre !== '') {
            $usuario = UserProxy::modelClass()::where('name', $nombre)->where('status', 1)->first();

            if ($usuario) {
                return $usuario->id;
            }
        }

        return $person->user_id;
    }
}
