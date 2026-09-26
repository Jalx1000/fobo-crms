<?php

namespace Webkul\Lead\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Qué pipelines son una ciudad de verdad y cuáles no.
 *
 * En kohlberg la ciudad ES el pipeline, pero "Sin ciudad" existe como pipeline
 * sin ser una ciudad: es dónde vive un pedido del que todavía no sabemos de
 * dónde escribe el cliente.
 *
 * La definición vive acá, en un solo lugar, porque el bug que motivó esta clase
 * fue justamente que cada módulo tenía la suya. CityRouter excluía "Sin ciudad"
 * en todos sus caminos; CitySyncService no, así que escribía "Sin ciudad" en la
 * ficha del cliente como si fuera un dato. A partir de ahí la casilla quedaba
 * ocupada y la ciudad que el agente averiguaba hablando con el cliente ya no se
 * podía guardar: 43 fichas quedaron así.
 *
 * "Sin ciudad" es la ausencia de un dato. Nunca se escribe, nunca bloquea.
 */
final class CityPipelines
{
    /**
     * Ids de los pipelines que NO son una ciudad.
     *
     * @return array<int, int>
     */
    public static function excludedIds(): array
    {
        $excluidas = array_map(
            fn ($n) => self::normalizar((string) $n),
            (array) config('whatsapp.handoff.exclude_cities', ['Sin ciudad']),
        );

        return DB::table('lead_pipelines')
            ->pluck('name', 'id')
            ->filter(fn ($nombre) => in_array(self::normalizar((string) $nombre), $excluidas, true))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * ¿Este pipeline dice de verdad de dónde es el cliente?
     */
    public static function isRealCity(?int $pipelineId): bool
    {
        return $pipelineId !== null && ! in_array($pipelineId, self::excludedIds(), true);
    }

    private static function normalizar(string $texto): string
    {
        return Str::of($texto)->trim()->lower()->ascii()->replace('.', '')->squish()->value();
    }
}
