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
use Webkul\Lead\Models\SourceProxy;
use Webkul\Lead\Models\TypeProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Services\CitySyncService;
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
    /**
     * Prefijo del título de los pedidos que abre este servicio. Es la única
     * marca que los distingue de uno cargado por una persona.
     */
    public const TITLE_PREFIX = 'WhatsApp - ';

    public function __construct(protected CityRouter $cities) {}

    /**
     * El pedido al que apunta una conversación: el último ABIERTO del contacto,
     * o uno nuevo si no tiene ninguno abierto.
     *
     * Un pedido cerrado no se enlaza. El #72 (entregado el 19/06) quedó
     * enlazado a la conversación que el cliente abrió el 30/10 con el número
     * nuevo, y el agente lo reabrió y le pisó el pedido de junio con el de
     * octubre. Pasó en 9 entregados y 6 cancelados.
     *
     * Devuelve null si está apagado por config o si algo falla — un pedido que
     * no se pudo abrir NUNCA puede costar el mensaje del cliente.
     */
    public function forPerson(?Model $person): ?int
    {
        if (! $person) {
            return null;
        }

        // Ya tiene un pedido abierto: se enlaza ese en vez de abrir otro.
        $existing = $person->leads()
            ->whereNotIn('lead_pipeline_stage_id', DB::table('lead_pipeline_stages')
                ->whereIn('code', CitySyncService::CLOSED_STAGE_CODES)
                ->select('id'))
            ->latest()
            ->first();

        if ($existing) {
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
                'title'                  => $title ?: self::TITLE_PREFIX.($person->name ?: 'Contacto'),
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
     * El pedido automático de una conversación que TODAVÍA NADIE TOCÓ.
     *
     * Es la pieza que evita el pedido duplicado: el CRM abre uno vacío cuando
     * alguien escribe, y minutos después el agente registra la venta por la API
     * creando OTRO. Medido el 2026-09-22: 81 pedidos fantasma, cada uno en el
     * pipeline y el asesor equivocados.
     *
     * "Intacto" es estricto a propósito. Si un asesor le puso etapa, monto o
     * productos, deja de ser fantasma y el pedido nuevo se crea aparte: nadie
     * pierde trabajo hecho a mano.
     *
     * Y se consume UNA SOLA VEZ. Por eso los multipedidos siguen funcionando:
     * el primero llena el fantasma, el segundo ya no encuentra ninguno y crea
     * uno nuevo, que es exactamente lo que tiene que pasar.
     */
    public function pendingLeadFor(Model $conversation): ?Model
    {
        if (! $conversation->lead_id) {
            return null;
        }

        $lead = LeadProxy::modelClass()::find($conversation->lead_id);

        return $lead && $this->isUntouched($lead) ? $lead : null;
    }

    /**
     * ¿Sigue siendo el pedido vacío que abrió el CRM?
     */
    public function isUntouched(Model $lead): bool
    {
        // El título es la firma de que lo abrió este servicio.
        if (! Str::startsWith((string) $lead->title, self::TITLE_PREFIX)) {
            return false;
        }

        if ((float) $lead->lead_value > 0) {
            return false;
        }

        if ((int) $lead->status !== 1) {
            return false;
        }

        if (DB::table('lead_products')->where('lead_id', $lead->id)->exists()) {
            return false;
        }

        /*
         * La etapa ya NO se mira.
         *
         * Decía "etapa distinta de la inicial = alguien lo movió en el kanban",
         * y era falso: el que mueve el fantasma es el propio agente. El #1697
         * pasó a "Cliente contactado" a las 10:48 por el Agente, y a las 10:53
         * el agente creó el #1698 aparte con el pedido real. Como ya no estaba
         * en la etapa inicial, el fantasma quedaba a salvo. Pasa en 23 casos,
         * todos en "Cliente contactado".
         *
         * También se probó detectarlo por el log de actividades —"sólo le
         * cambiaron la etapa"— y no funciona: de 218 pedidos automáticos, el
         * log marca 218 como "sólo etapa", incluidos 24 que SÍ tienen productos
         * o valor. Los cambios de contenido no quedan registrados con un
         * atributo que se pueda distinguir.
         *
         * Lo que sí distingue es el estado, que es lo que se mira arriba: sin
         * productos y sin valor. Lo único que la etapa aporta es si el pedido
         * ya está cerrado, y eso se respeta: un fantasma que alguien mandó a
         * Cancelados o Descartado no se vuelve a tocar.
         */
        $etapa = DB::table('lead_pipeline_stages')
            ->where('id', $lead->lead_pipeline_stage_id)
            ->value('code');

        return ! in_array($etapa, CitySyncService::CLOSED_STAGE_CODES, true);
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
