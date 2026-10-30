<?php

namespace Webkul\Admin\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Estado compartido de la sincronización con ShareMeData.
 *
 * - `paused`: flag persistente (tabla settings) que detiene el sync automático
 *   (scheduler + auto-arranque al entrar a Leads/Calendario). NO afecta al sync
 *   manual forzado desde el botón "Sincronizar ahora".
 * - `last_finished`: marca temporal (Cache) usada para el cooldown del
 *   auto-arranque, para no disparar un sync en cada carga de página.
 */
class SmdSyncState
{
    public const PAUSED_SETTING = 'smd_sync_paused';

    public const LAST_FINISHED_KEY = 'smd:sync:last_finished';

    /** Cooldown del auto-arranque (segundos). */
    public const AUTO_COOLDOWN = 300;

    public static function isPaused(): bool
    {
        return DB::table('settings')
            ->where('key', self::PAUSED_SETTING)
            ->value('value') === '1';
    }

    public static function setPaused(bool $paused): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::PAUSED_SETTING],
            ['value' => $paused ? '1' : '0', 'updated_at' => now()],
        );
    }

    public static function markFinished(): void
    {
        Cache::put(self::LAST_FINISHED_KEY, now()->timestamp, 86400);
    }

    /**
     * ¿Pasó suficiente tiempo desde el último sync como para auto-disparar otro?
     */
    public static function autoCooldownElapsed(): bool
    {
        $last = Cache::get(self::LAST_FINISHED_KEY);

        return $last === null || (now()->timestamp - (int) $last) >= self::AUTO_COOLDOWN;
    }
}
