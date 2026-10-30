<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Webkul\Admin\Services\SmdAppointmentSyncService;
use Webkul\Admin\Support\SmdSyncState;

/**
 * Ejecuta la sincronización de citas SMD→CRM en segundo plano y publica su
 * estado en Cache para que el frontend lo consulte por polling.
 *
 * Concurrencia: el guard atómico `LOCK_KEY` (Cache::add en el controller) impide
 * dos sincronizaciones simultáneas; este job lo libera al terminar.
 */
class SyncSmdAppointmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const STATUS_KEY = 'smd:sync:status';

    public const LOCK_KEY = 'smd:sync:lock';

    public const STATUS_TTL = 3600;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $days = 7,
    ) {}

    public function handle(SmdAppointmentSyncService $service): void
    {
        try {
            $summary = $service->run($this->days);

            Cache::put(self::STATUS_KEY, [
                'state'       => 'done',
                'summary'     => $summary,
                'days'        => $this->days,
                'finished_at' => now()->toIso8601String(),
            ], self::STATUS_TTL);
        } catch (\Throwable $e) {
            $this->markFailed($e->getMessage());

            throw $e;
        } finally {
            SmdSyncState::markFinished();
            Cache::forget(self::LOCK_KEY);
        }
    }

    /**
     * Si el job falla definitivamente (timeout, excepción tras los reintentos),
     * deja el estado en `failed` y libera el lock.
     */
    public function failed(\Throwable $e): void
    {
        $this->markFailed($e->getMessage());
        Cache::forget(self::LOCK_KEY);
    }

    protected function markFailed(string $error): void
    {
        Cache::put(self::STATUS_KEY, [
            'state'       => 'failed',
            'error'       => $error,
            'finished_at' => now()->toIso8601String(),
        ], self::STATUS_TTL);
    }
}
