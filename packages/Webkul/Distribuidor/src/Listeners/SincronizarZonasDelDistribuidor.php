<?php

namespace Webkul\Distribuidor\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\Distribuidor\Services\ZonasDelDistribuidor;

/**
 * Se creó o se editó un usuario: sus zonas de reparto pasan a ser las de sus grupos.
 *
 * El controlador dispara el evento después de guardar los grupos, así que acá ya
 * están los definitivos.
 */
class SincronizarZonasDelDistribuidor
{
    public function __construct(protected ZonasDelDistribuidor $zonas) {}

    public function handle($user): void
    {
        if (! $user?->id) {
            return;
        }

        $resultado = $this->zonas->sincronizar((int) $user->id);

        if ($resultado['agregadas'] || $resultado['quitadas']) {
            Log::info('Zonas de distribuidor actualizadas', ['user_id' => $user->id] + $resultado);
        }
    }
}
