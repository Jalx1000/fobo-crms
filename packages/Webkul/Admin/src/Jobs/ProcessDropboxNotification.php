<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\SmdAppointmentSyncService;

/**
 * Procesa las notificaciones del webhook de Dropbox drenando el cursor.
 *
 * Es único: Dropbox notifica cada cambio y una sola corrida drena todos los
 * pendientes vía `has_more`, así que un segundo job simultáneo solo duplicaría
 * trabajo sobre el mismo cursor.
 */
class ProcessDropboxNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Con descarga en paralelo un lote entra holgado; 300s da margen a backlogs. */
    public int $timeout = 300;

    /** Si el job muere sin liberar el lock, se libera solo pasado este tiempo. */
    public int $uniqueFor = 600;

    public function handle(DropboxService $dropbox, SmdAppointmentSyncService $sync): void
    {
        $cursor = DB::table('settings')
            ->where('key', 'dropbox_cursor')
            ->value('value');

        // Sin cursor no hay de dónde partir: se inicializa una vez y las próximas
        // notificaciones ya drenan el delta.
        if (! $cursor) {
            Log::warning('[DropboxWebhook] No hay cursor guardado, inicializando...');
            $cursor = $this->initCursor($dropbox);

            if (! $cursor) {
                Log::error('[DropboxWebhook] No se pudo inicializar el cursor. Revisá las credenciales de Dropbox.');

                return;
            }
        }

        $summary = $sync->emptySummary();

        do {
            $result = $dropbox->listChanges($cursor);

            // Cursor reseteado por Dropbox: se re-inicializa a "ahora" y se corta.
            // El histórico lo cubre el poll/manual; el webhook sigue con lo nuevo.
            if (! empty($result['reset'])) {
                Log::warning('[DropboxWebhook] Cursor reseteado, reinicializando a estado actual');
                $this->initCursor($dropbox);

                break;
            }

            $entries = $result['entries'];
            $cursor = $result['cursor'];
            $hasMore = $result['has_more'];

            // Los archivos llegan en el orden en que Dropbox registró los cambios;
            // ordenarlos por nombre (`event-YYYY-MM-DD-HHMM-<id>`) los deja en orden
            // cronológico dentro del lote. El descarte por `updated_at` en
            // processPayload() cubre el resto.
            usort($entries, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

            // Descarga EN PARALELO + salto de lo ya procesado (mismo camino que el
            // comando manual). Bajar de a uno era lo que hacía morir el job por timeout.
            $sync->processEntries($entries, $summary);

            DB::table('settings')->updateOrInsert(
                ['key' => 'dropbox_cursor'],
                ['value' => $cursor, 'updated_at' => now()]
            );

        } while ($hasMore);

        Log::info('[DropboxWebhook] Procesamiento completado', $summary);
    }

    /**
     * Inicializa (o re-inicializa) el cursor de Dropbox a "ahora" y lo guarda.
     */
    private function initCursor(DropboxService $dropbox): ?string
    {
        $folder = config('smd.dropbox.appointments_folder', '/smd-events');
        $cursor = $dropbox->getInitialCursor($folder);

        if ($cursor) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'dropbox_cursor'],
                ['value' => $cursor, 'updated_at' => now()]
            );
        }

        return $cursor;
    }
}
