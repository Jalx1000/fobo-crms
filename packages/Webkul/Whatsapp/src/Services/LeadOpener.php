<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Models\PipelineProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Services\CitySyncService;

/**
 * Abre el pedido de quien escribe por WhatsApp (traído de Kohlberg).
 *
 * Sin esto, quien escribía quedaba como contacto pero sin pedido: el agente es el
 * mismo para todos los CRM y da por hecho que el pedido ya existe, así que al saber la
 * zona intentaba ACTUALIZARLO y recibía 404 (conversación 7710). Y cuando sí creaba
 * uno, la conversación no lo conocía, el evento seguía con `lead: null` y el agente
 * creaba otro: pedidos duplicados (8285).
 *
 * El pedido se abre vacío, en la etapa inicial (Prospectos) de la ciudad del cliente
 * o en «Sin ciudad». El dueño lo pone el reparto de Sensia al fijarse la ciudad: un
 * cliente nuevo va a Huascar, uno conocido a su distribuidor.
 */
class LeadOpener
{
    /**
     * Prefijo del título de los pedidos que abre este servicio. Es la marca que los
     * distingue de uno cargado por una persona o por el agente.
     */
    public const TITLE_PREFIX = 'WhatsApp - ';

    /**
     * El pedido al que apunta una conversación: el abierto más reciente del
     * contacto, o uno nuevo si no tiene ninguno abierto.
     *
     * Devuelve null si está apagado por config o si algo falla: un pedido que no se
     * pudo abrir NUNCA puede costar el mensaje del cliente.
     */
    public function forPerson(?Model $person): ?int
    {
        if (! $person) {
            return null;
        }

        // Un pedido abierto se reusa. Uno entregado o cancelado no: si vuelve a
        // escribir, es una compra nueva.
        $abierto = LeadProxy::modelClass()::query()
            ->where('person_id', $person->id)
            ->whereHas('stage', fn ($q) => $q->whereNotIn('code', CitySyncService::CLOSED_STAGE_CODES))
            ->latest('id')
            ->first();

        if ($abierto) {
            return (int) $abierto->id;
        }

        if (! config('whatsapp.auto_create_lead', true)) {
            return null;
        }

        return optional($this->open($person))->id;
    }

    public function open(Model $person): ?Model
    {
        try {
            $pipeline = $this->pipelineFor($person);
            $stage = $pipeline?->stages()->orderBy('sort_order')->first();

            if (! $pipeline || ! $stage) {
                return null;
            }

            // 'Whatsapp': así se llama la fuente que ya existe.
            $source = DB::table('lead_sources')->where('name', 'Whatsapp')->value('id')
                ?? DB::table('lead_sources')->value('id');

            // Cliente antiguo si ya compró alguna vez.
            $tipos = DB::table('lead_types')->orderBy('id')->pluck('id');
            $yaCompro = LeadProxy::modelClass()::where('person_id', $person->id)->exists();

            Event::dispatch('lead.create.before');

            $lead = app(LeadRepository::class)->create([
                'entity_type'            => 'leads',
                'title'                  => self::TITLE_PREFIX.($person->name ?: 'Contacto'),
                'lead_value'             => 0,
                'person'                 => ['id' => $person->id],
                'lead_pipeline_id'       => $pipeline->id,
                'lead_pipeline_stage_id' => $stage->id,
                'lead_source_id'         => $source,
                'lead_type_id'           => $yaCompro ? ($tipos[1] ?? $tipos[0] ?? null) : ($tipos[0] ?? null),
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
            // Corre dentro de la ingesta del webhook: si falla, el mensaje del cliente
            // se guarda igual. Un pedido lo puede crear después una persona.
            Log::warning('WhatsApp: no se pudo abrir el pedido', [
                'person_id' => $person->id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ¿Sigue siendo el pedido vacío que abrió el CRM? Sin productos, sin valor y
     * abierto. Si alguien le cargó algo, deja de serlo y nadie pierde trabajo.
     */
    public function isUntouched(Model $lead): bool
    {
        if (! Str::startsWith((string) $lead->title, self::TITLE_PREFIX)) {
            return false;
        }

        if ((float) $lead->lead_value > 0 || (int) $lead->status !== 1) {
            return false;
        }

        if (DB::table('lead_products')->where('lead_id', $lead->id)->exists()) {
            return false;
        }

        $etapa = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code');

        return ! in_array($etapa, CitySyncService::CLOSED_STAGE_CODES, true);
    }

    /**
     * La ciudad del contacto (su ficha o su último pedido) o «Sin ciudad». Nunca la
     * ciudad por defecto: marcaría al cliente con una ciudad que nadie le preguntó.
     */
    public function pipelineFor(Model $person): ?Model
    {
        $model = PipelineProxy::modelClass();

        $ciudad = class_exists(\Webkul\Distribuidor\Services\Reparto::class)
            ? app(\Webkul\Distribuidor\Services\Reparto::class)->ciudadDelCliente((int) $person->id)
            : null;

        if ($ciudad && $pipeline = $model::find($ciudad)) {
            return $pipeline;
        }

        return $model::where('name', (string) config('whatsapp.unknown_city_pipeline', 'Sin ciudad'))->first()
            ?? $model::where('is_default', 1)->first();
    }
}
