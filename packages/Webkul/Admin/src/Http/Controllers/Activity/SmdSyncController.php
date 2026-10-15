<?php

namespace Webkul\Admin\Http\Controllers\Activity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Jobs\SyncSmdAppointmentsJob;
use Webkul\Admin\Support\SmdSyncState;

class SmdSyncController extends Controller
{
    /** Rango por defecto de días a sincronizar. */
    public const DEFAULT_DAYS = 7;

    /**
     * Dispara la sincronización de citas SMD→CRM en segundo plano.
     *
     * Modo `auto` (al entrar a Leads/Calendario): respeta la pausa y un cooldown
     * para no spamear. Modo manual ("Sincronizar ahora"): fuerza siempre (solo
     * respeta el lock de concurrencia).
     */
    public function sync(Request $request): JsonResponse
    {
        $this->authorizeSync();

        $auto = $request->boolean('auto');

        if ($auto && SmdSyncState::isPaused()) {
            return response()->json(['state' => 'paused', 'skipped' => true]);
        }

        if ($auto && ! SmdSyncState::autoCooldownElapsed()) {
            return response()->json(['state' => 'idle', 'skipped' => true]);
        }

        // Cache::add es atómico (SETNX en Redis): solo el primero gana el lock.
        $acquired = Cache::add(
            SyncSmdAppointmentsJob::LOCK_KEY,
            now()->toIso8601String(),
            (new SyncSmdAppointmentsJob)->timeout,
        );

        if (! $acquired) {
            // Auto: silencioso. Manual: informa que ya hay uno en curso.
            return response()->json([
                'state'   => 'running',
                'skipped' => $auto,
                'message' => $auto ? null : 'Ya hay una sincronización con ShareMeData en curso.',
            ], $auto ? 200 : 409);
        }

        Cache::put(SyncSmdAppointmentsJob::STATUS_KEY, [
            'state'      => 'running',
            'started_at' => now()->toIso8601String(),
        ], SyncSmdAppointmentsJob::STATUS_TTL);

        SyncSmdAppointmentsJob::dispatch(self::DEFAULT_DAYS);

        return response()->json([
            'state'   => 'running',
            'message' => 'Sincronización con ShareMeData iniciada.',
        ], 202);
    }

    /**
     * Estado actual del sync (para polling y para reflejar el estado al cargar).
     */
    public function status(): JsonResponse
    {
        $this->authorizeSync();

        $status = Cache::get(SyncSmdAppointmentsJob::STATUS_KEY, ['state' => 'idle']);

        $status['paused'] = SmdSyncState::isPaused();
        $status['message'] = $this->humanMessage($status);

        return response()->json($status);
    }

    /**
     * Pausa o reanuda la sincronización automática (toggle persistente).
     */
    public function pause(Request $request): JsonResponse
    {
        $this->authorizeSync();

        $paused = $request->boolean('paused');

        SmdSyncState::setPaused($paused);

        return response()->json([
            'paused'  => $paused,
            'message' => $paused
                ? 'Sincronización automática pausada.'
                : 'Sincronización automática reanudada.',
        ]);
    }

    protected function authorizeSync(): void
    {
        if (! bouncer()->hasPermission('activities')) {
            abort(403);
        }
    }

    /**
     * Mensaje legible según el estado, para el toast del frontend.
     */
    protected function humanMessage(array $status): ?string
    {
        return match ($status['state'] ?? 'idle') {
            'running' => 'Sincronización con ShareMeData en curso…',
            'failed'  => 'La sincronización con ShareMeData falló. Revisa los logs.',
            'done'    => sprintf(
                'Sincronización completada: %d creadas, %d actualizadas, %d canceladas.',
                $status['summary']['creados'] ?? 0,
                $status['summary']['actualizados'] ?? 0,
                $status['summary']['cancelados'] ?? 0,
            ),
            default => null,
        };
    }
}
