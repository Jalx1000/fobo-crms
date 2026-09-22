<?php

namespace Webkul\Whatsapp\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Services\CitySyncService;

/**
 * La ciudad (pipeline) de una conversación de WhatsApp.
 *
 * Una conversación NO tiene ciudad propia: hay que deducirla. Medido sobre las
 * 40 conversaciones reales, la cascada cubre 19; las otras 21 no tienen ciudad
 * por ningún camino, y esa es la opción más usada del filtro.
 *
 * El orden es el mismo que usa CityRouter al derivar:
 *
 *   1. handoff_city_id  — resuelto al derivar, es el más explícito
 *   2. cliente_ciudad   — atributo del contacto; existe sin que haya un pedido
 *   3. pipeline del lead — último recurso
 *
 * Los dos últimos coinciden hoy en las 18 que tienen dato, con CERO
 * discrepancias, porque CitySyncService los mantiene sincronizados. La cascada
 * igual está completa: si mañana uno de los dos falta, el filtro no se cae.
 *
 * Es una expresión SQL y no un accessor del modelo porque la usan el WHERE del
 * filtro, el SELECT de la fila y el GROUP BY del conteo. En PHP habría que
 * traer todo a memoria para filtrar o agrupar.
 */
final class ConversationCity
{
    /**
     * El valor que el filtro usa para "sin ciudad".
     *
     * Texto y no 0: un `city=0` se confunde con "sin filtro".
     */
    public const NONE = 'none';

    /**
     * SQL que resuelve el id de ciudad de la conversación en curso.
     *
     * Sirve dentro de un select, un where o un group by sobre
     * whatsapp_conversations.
     */
    public static function sql(string $table = 'whatsapp_conversations'): string
    {
        $p = DB::getTablePrefix();
        $conv = $p.$table;

        // El id del atributo se busca por code: hardcodear el 70 se rompe en
        // cuanto alguien reinstale o migre el atributo.
        $attributeId = (int) self::personCityAttributeId();

        return "COALESCE(
            {$conv}.handoff_city_id,
            (
                select wa_av.integer_value
                from {$p}attribute_values wa_av
                where wa_av.entity_id = {$conv}.person_id
                  and wa_av.entity_type = 'persons'
                  and wa_av.attribute_id = {$attributeId}
                  and wa_av.integer_value is not null
                limit 1
            ),
            (
                select wa_l.lead_pipeline_id
                from {$p}leads wa_l
                where wa_l.person_id = {$conv}.person_id
                order by (wa_l.status = 1) desc, wa_l.id desc
                limit 1
            )
        )";
    }

    /**
     * Los pipelines que, para el asesor, son lo mismo que no tener ciudad.
     *
     * "Sin ciudad" existe como pipeline real y hay conversaciones que caen ahí.
     * Mostrarlo como una ciudad más obligaría a elegir entre dos opciones que
     * significan lo mismo.
     *
     * @return array<int, int>
     */
    public static function noneEquivalentPipelineIds(): array
    {
        $nombres = (array) config('whatsapp.handoff.exclude_cities', ['Sin ciudad']);

        return DB::table('lead_pipelines')
            ->whereIn('name', $nombres)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected static function personCityAttributeId(): ?int
    {
        static $id;

        if ($id !== null) {
            return $id ?: null;
        }

        $id = (int) DB::table('attributes')
            ->where('code', CitySyncService::PERSON_CITY_ATTRIBUTE_CODE)
            ->where('entity_type', 'persons')
            ->value('id');

        return $id ?: null;
    }
}
