<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza citas desde la carpeta Dropbox de ShareMeData hacia el CRM.
 *
 * Recorre los archivos de los últimos N días, los compara contra
 * `smd_synced_events` (idempotente vía hash del payload) y crea, actualiza o
 * cancela las citas correspondientes mediante IncomingAppointmentService.
 *
 * Es la fuente única de la lógica de sync: la usan tanto el comando
 * `smd:sync-dropbox` como el Job disparado desde el calendario.
 */
class SmdAppointmentSyncService
{
    public function __construct(
        protected DropboxService $dropbox,
        protected IncomingAppointmentService $incoming,
        protected SmdStatusMapper $mapper,
    ) {}

    /**
     * @param  int  $days  Cantidad de días hacia atrás a revisar (incluye la fecha base).
     * @param  callable|null  $onLog  Callback opcional para emitir progreso (usado por el comando).
     * @param  Carbon|null  $baseDate  Fecha base; por defecto hoy. La revisión va hacia atrás desde aquí.
     * @return array{creados:int, actualizados:int, cancelados:int, sin_cambios:int, obsoletos:int, errores:int}
     */
    public function run(int $days = 1, ?callable $onLog = null, ?Carbon $baseDate = null): array
    {
        $days = max(1, $days);

        $summary = $this->emptySummary();

        $baseDate ??= now();
        $files = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $baseDate->copy()->subDays($i)->toDateString();
            $dayFiles = $this->dropbox->listFilesForDate($date);

            if ($onLog) {
                $onLog("{$date} - ".count($dayFiles).' archivos');
            }

            $files = array_merge($files, $dayFiles);
        }

        // SMD nombra los archivos `event-YYYY-MM-DD-HHMM-<id>.json`, así que ordenar
        // por nombre los deja en orden cronológico y la última versión de cada cita
        // se aplica al final. Es solo una optimización: la corrección real la
        // garantiza el descarte por `updated_at` dentro de processPayload(), que
        // no depende del orden en que Dropbox devuelva los archivos.
        usort($files, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        $this->processEntries($files, $summary, $onLog);

        Log::info('[SmdAppointmentSync] Sincronización completada', $summary + ['days' => $days]);

        return $summary;
    }

    /**
     * Resumen vacío con TODAS las claves de outcome. Centralizado para que el
     * comando, el job del webhook y la UI cuenten igual (y no falte `bloqueos`,
     * que processPayload() puede devolver).
     *
     * @return array<string, int>
     */
    public function emptySummary(): array
    {
        return [
            'creados'      => 0,
            'actualizados' => 0,
            'cancelados'   => 0,
            'bloqueos'     => 0,
            'sin_cambios'  => 0,
            'obsoletos'    => 0,
            'en_proceso'   => 0,
            'errores'      => 0,
        ];
    }

    /**
     * Procesa una lista de entries de Dropbox (comando y webhook comparten esto):
     * descarta lo ya procesado, baja EN PARALELO por lotes y aplica cada payload.
     *
     * Bajar de a uno era el cuello de botella (una latencia de round-trip por
     * archivo, en serie): con cientos/miles de archivos el job del webhook moría
     * por timeout. Descargar en paralelo (downloadManyJson) y saltar lo ya
     * procesado (smd_processed_files) lo vuelve viable.
     *
     * La identidad del archivo es ruta + `content_hash`, NO la ruta sola. El
     * nombre que usa SMD -`event-YYYY-MM-DD-HHMM-<id>.json`- tiene resolución de
     * minutos, así que una cita creada y editada dentro del mismo minuto se
     * escribe dos veces en la misma ruta: si dedujéramos solo por ruta, la
     * edición se perdería en silencio sin llegar nunca a processPayload().
     *
     * @param  array  $files  Entries de Dropbox (cada uno con `path_lower`, `name` y `content_hash`).
     */
    public function processEntries(array $files, array &$summary, ?callable $onLog = null): void
    {
        $paths = array_filter(array_column($files, 'path_lower'));

        if (! $paths) {
            return;
        }

        $procesados = collect();

        foreach (array_chunk($paths, 500) as $chunk) {
            $procesados = $procesados->merge(
                DB::table('smd_processed_files')
                    ->whereIn('path', $chunk)
                    ->pluck('content_hash', 'path')
            );
        }

        $pendientes = array_values(array_filter(
            $files,
            fn ($file) => ! $this->yaProcesado($procesados, $file)
        ));

        if ($onLog && count($files) !== count($pendientes)) {
            $onLog(sprintf('%d archivos, %d ya procesados - se bajan %d', count($files), count($files) - count($pendientes), count($pendientes)));
        }

        $summary['sin_cambios'] += count($files) - count($pendientes);

        // El lote se mantiene acotado para no cargar miles de payloads en memoria
        // de una sola vez; la concurrencia real la fija downloadManyJson.
        $lote = max(1, (int) config('smd.dropbox.batch_size', 100));

        foreach (array_chunk($pendientes, $lote) as $grupo) {
            $rutas = array_column($grupo, 'path_lower');
            $payloads = $this->dropbox->downloadManyJson($rutas);

            if ($onLog) {
                $onLog(sprintf('  descargados %d/%d', count($payloads), count($rutas)));
            }

            foreach ($grupo as $file) {
                $this->processFile($file, $summary, $onLog, $payloads[$file['path_lower']] ?? null);
            }
        }
    }

    /**
     * ¿Este entry de Dropbox ya se bajó con ESTE contenido?
     *
     * Un hash guardado en null es una fila anterior a que empezáramos a
     * registrarlo: no hay con qué comparar, así que se rebaja una vez. Cuesta
     * una descarga y deja la fila al día; procesar de nuevo un payload idéntico
     * no tiene efectos secundarios (processPayload() lo corta en `sin_cambios`).
     *
     * @param  \Illuminate\Support\Collection  $procesados  path => content_hash
     */
    private function yaProcesado($procesados, array $file): bool
    {
        $path = $file['path_lower'] ?? '';

        if (! $procesados->has($path)) {
            return false;
        }

        $guardado = $procesados->get($path);

        return $guardado !== null && $guardado === ($file['content_hash'] ?? null);
    }

    /**
     * Procesa un archivo individual de Dropbox, actualizando el resumen por referencia.
     *
     * @param  array|null  $json  Payload ya descargado en lote. Si viene null se
     *                            baja acá, para que el job del webhook y la UI
     *                            puedan seguir usando el camino de a uno.
     */
    protected function processFile(array $file, array &$summary, ?callable $onLog, ?array $json = null): void
    {
        try {
            $json ??= $this->dropbox->downloadJson($file['path_lower']);

            if (! $json || ! isset($json['_id'])) {
                Log::warning('[SmdAppointmentSync] JSON sin _id o inválido', [
                    'file' => $file['path_lower'] ?? '?',
                ]);
                $summary['errores']++;

                return;
            }

            $outcome = $this->processPayload($json, $file['path_lower']);

            $summary[$outcome]++;

            // Anotado salvo que no se haya podido aplicar: un archivo obsoleto o
            // que no cambió nada tampoco hay que volver a bajarlo. Se omite si
            // hubo excepción o si la cita estaba tomada por otro worker
            // (`en_proceso`), para que la próxima corrida lo reintente.
            if ($outcome !== 'en_proceso') {
                $this->markFileProcessed($file['path_lower'], $outcome, $file['content_hash'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::error('[SmdAppointmentSync] Error procesando archivo', [
                'file'  => $file['path_lower'] ?? '?',
                'error' => $e->getMessage(),
            ]);

            if ($onLog) {
                $onLog('  ✗ '.($file['path_lower'] ?? '?').': '.$e->getMessage());
            }

            $summary['errores']++;
        }
    }

    /**
     * Aplica un payload de SMD al CRM (crear, actualizar o cancelar la cita).
     *
     * Es el único punto por el que pasan los tres orígenes del sync (comando
     * `smd:sync-dropbox`, job del webhook y job de la UI), por lo que aquí viven
     * el descarte de payloads obsoletos y el lock por cita.
     *
     * @return string La clave del resumen que corresponde incrementar.
     */
    public function processPayload(array $json, ?string $sourceFile = null): string
    {
        $externalId = $json['_id'];

        // Un lock por cita evita que el cron y el webhook procesen el mismo evento
        // a la vez y creen Lead + Activity duplicados: ambos verían que no existe
        // antes de que ninguno alcance a insertar en smd_synced_events.
        $lock = Cache::lock('smd:event:'.$externalId, 60);

        // Si otro worker la tiene, se espera un poco: lo normal es que termine en
        // segundos y así esta versión se aplica en la misma corrida. Si no se
        // libera, se devuelve `en_proceso`, que NO marca el archivo como
        // procesado: antes se devolvía `sin_cambios`, el archivo quedaba anotado
        // y esa versión no se aplicaba nunca (AUD-04).
        try {
            $acquired = $lock->block((int) config('smd.dropbox.lock_wait_seconds', 10));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            Log::info('[SmdAppointmentSync] Cita en proceso por otro worker, se reintenta en la próxima corrida', [
                'external_id' => $externalId,
            ]);

            return 'en_proceso';
        }

        try {
            return $this->applyPayload($json, $externalId, $sourceFile);
        } finally {
            $lock->release();
        }
    }

    private function applyPayload(array $json, string $externalId, ?string $sourceFile): string
    {
        $status = $json['status'] ?? '';
        $updatedAt = $this->payloadUpdatedAt($json);
        $shouldCancel = ($json['archived'] ?? false) || $this->mapper->isCancelled($status);

        $existing = DB::table('smd_synced_events')
            ->where('external_id', $externalId)
            ->first();

        if ($this->isStale($existing, $updatedAt)) {
            Log::info('[SmdAppointmentSync] Payload obsoleto descartado', [
                'external_id'    => $externalId,
                'file'           => $sourceFile,
                'payload_update' => $updatedAt?->toIso8601String(),
                'stored_update'  => $existing->source_updated_at,
            ]);

            return 'obsoletos';
        }

        // Antes que nada: si el payload es idéntico al ya aplicado no hay nada que
        // hacer. Va antes de la cancelación porque si no, una cita cancelada se
        // volvería a cancelar en cada corrida del cron, reescribiendo `archived_at`
        // y re-sincronizando el tag cada 5 minutos.
        if ($existing && md5($existing->raw_payload ?? '') === md5(json_encode($json))) {
            return 'sin_cambios';
        }

        if ($shouldCancel) {
            if ($existing) {
                // Un mismo archivo puede traer la cancelación y una edición del
                // título o la fecha. Aplicar el contenido antes de cerrar el lead
                // evita perder esa edición. Sin activity_id no hay nada que
                // actualizar, y updateDropbox() crearía una cita nueva para un
                // evento ya cancelado.
                if ($existing->activity_id) {
                    $result = $this->incoming->updateDropbox($json, $existing);

                    // updateDropbox() puede estrenar lead y actividad: un bloqueo
                    // que vuelve con paciente se promueve a cita. Hay que cancelar
                    // ESA cita y guardar sus ids; si no, queda una cita activa y
                    // desvinculada, y cada cambio futuro del evento crea otra
                    // (AUD-13). Pasó al cancelar desde SMD citas creadas por el CRM.
                    $existing = (object) array_merge((array) $existing, [
                        'lead_id'     => $result['lead_id'] ?? $existing->lead_id,
                        'activity_id' => $result['activity_id'] ?? $existing->activity_id,
                    ]);
                }

                $cancelled = $this->incoming->cancelDropbox($externalId, $existing);

                // cancelDropbox() borra el time_off de un bloqueo cancelado: no
                // dejar el registro apuntando a una actividad que ya no existe.
                if (array_key_exists('activity_id', $cancelled)) {
                    $existing->activity_id = $cancelled['activity_id'];
                }

                DB::table('smd_synced_events')
                    ->where('external_id', $externalId)
                    ->update($this->withSourceUpdatedAt([
                        'raw_payload' => json_encode($json),
                        'status'      => $status,
                        'lead_id'     => $existing->lead_id,
                        'activity_id' => $existing->activity_id,
                        'archived_at' => now(),
                        'updated_at'  => now(),
                    ], $updatedAt));
            } else {
                // Cancelada y nunca vista antes: no se crea la cita -ya viene
                // cerrada de SMD- pero sí queda constancia. Sin esta fila el
                // evento no figura como sincronizado, se vuelve a evaluar en
                // cada corrida y parece que faltara respecto del archivo origen.
                DB::table('smd_synced_events')->insert($this->withSourceUpdatedAt([
                    'external_id'  => $externalId,
                    'source_file'  => $sourceFile,
                    'activity_id'  => null,
                    'lead_id'      => null,
                    'raw_payload'  => json_encode($json),
                    'status'       => $status,
                    'archived_at'  => now(),
                    'processed_at' => now(),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ], $updatedAt));
            }

            return 'cancelados';
        }

        if ($existing) {
            // Se capturan los ids que devuelve: un bloqueo promovido a cita
            // estrena lead_id y activity_id, y sin guardarlos la próxima corrida
            // lo volvería a ver como bloqueo.
            $result = $this->incoming->updateDropbox($json, $existing);

            DB::table('smd_synced_events')
                ->where('external_id', $externalId)
                ->update($this->withSourceUpdatedAt([
                    'raw_payload' => json_encode($json),
                    'status'      => $status,
                    'lead_id'     => $result['lead_id'] ?? $existing->lead_id,
                    'activity_id' => $result['activity_id'] ?? $existing->activity_id,
                    'updated_at'  => now(),
                ], $updatedAt));

            return 'actualizados';
        }

        $result = $this->incoming->processDropbox($json);

        // Bloqueo de agenda: un evento sin paciente. Nunca genera lead ni
        // persona. Si tiene doctor, processDropbox creó una actividad de
        // ocupación (time_off) y su id viene en el resultado; si no hay doctor,
        // no hay nada que ocupar y activity_id queda null. En ambos casos se
        // registra para no re-evaluarlo en cada corrida.
        if (($result['skipped'] ?? null) === 'block' || ($result['block'] ?? false)) {
            DB::table('smd_synced_events')->insert($this->withSourceUpdatedAt([
                'external_id'  => $externalId,
                'source_file'  => $sourceFile,
                'activity_id'  => $result['activity_id'] ?? null,
                'lead_id'      => null,
                'raw_payload'  => json_encode($json),
                'status'       => $status,
                'processed_at' => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ], $updatedAt));

            return 'bloqueos';
        }

        DB::table('smd_synced_events')->insert($this->withSourceUpdatedAt([
            'external_id'  => $externalId,
            'source_file'  => $sourceFile,
            'activity_id'  => $result['activity_id'] ?? null,
            'lead_id'      => $result['lead_id'] ?? null,
            'raw_payload'  => json_encode($json),
            'status'       => $status,
            'processed_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $updatedAt));

        return 'creados';
    }

    /**
     * Deja constancia de que el archivo ya se bajó CON ESTE contenido, para que
     * la próxima corrida no lo vuelva a pedir mientras no cambie. Nunca debe
     * hacer fallar la sincronización.
     */
    private function markFileProcessed(?string $path, string $outcome, ?string $contentHash = null): void
    {
        if (! $path) {
            return;
        }

        try {
            DB::table('smd_processed_files')->updateOrInsert(
                ['path' => $path],
                ['outcome' => $outcome, 'content_hash' => $contentHash, 'processed_at' => now()],
            );
        } catch (\Throwable $e) {
            Log::warning('[SmdAppointmentSync] No se pudo anotar el archivo procesado', [
                'file'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * `updated_at` del payload de SMD, normalizado a UTC. Null si no viene
     * (los archivos anteriores al cambio de julio/2026 no lo traían).
     */
    private function payloadUpdatedAt(array $json): ?Carbon
    {
        $raw = $json['updated_at'] ?? null;

        return $raw ? Carbon::parse($raw)->utc() : null;
    }

    /**
     * ¿El payload es una versión anterior a la ya aplicada?
     *
     * SMD deja un archivo por cada cambio y no borra los viejos, así que la misma
     * cita reaparece en varias carpetas. Sin esto, el archivo más antiguo pisa al
     * más nuevo y revierte la modificación.
     */
    private function isStale(?object $existing, ?Carbon $incoming): bool
    {
        if (! $existing || ! $incoming || ! $existing->source_updated_at) {
            return false;
        }

        return $incoming->lt(Carbon::parse($existing->source_updated_at, 'UTC'));
    }

    /**
     * Agrega `source_updated_at` solo si el payload lo trae, para no borrar la
     * referencia guardada cuando llega un archivo legacy sin `updated_at`.
     */
    private function withSourceUpdatedAt(array $attributes, ?Carbon $updatedAt): array
    {
        if ($updatedAt) {
            $attributes['source_updated_at'] = $updatedAt->format('Y-m-d H:i:s');
        }

        return $attributes;
    }
}
