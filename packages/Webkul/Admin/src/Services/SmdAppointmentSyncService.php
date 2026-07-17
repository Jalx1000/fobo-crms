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

        $summary = [
            'creados'      => 0,
            'actualizados' => 0,
            'cancelados'   => 0,
            'bloqueos'     => 0,
            'sin_cambios'  => 0,
            'obsoletos'    => 0,
            'errores'      => 0,
        ];

        $baseDate ??= now();
        $files = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $baseDate->copy()->subDays($i)->toDateString();
            $dayFiles = $this->dropbox->listFilesForDate($date);

            if ($onLog) {
                $onLog("{$date} — ".count($dayFiles).' archivos');
            }

            $files = array_merge($files, $dayFiles);
        }

        // SMD nombra los archivos `event-YYYY-MM-DD-HHMM-<id>.json`, así que ordenar
        // por nombre los deja en orden cronológico y la última versión de cada cita
        // se aplica al final. Es solo una optimización: la corrección real la
        // garantiza el descarte por `updated_at` dentro de processPayload(), que
        // no depende del orden en que Dropbox devuelva los archivos.
        usort($files, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        foreach ($files as $file) {
            $this->processFile($file, $summary, $onLog);
        }

        Log::info('[SmdAppointmentSync] Sincronización completada', $summary + ['days' => $days]);

        return $summary;
    }

    /**
     * Procesa un archivo individual de Dropbox, actualizando el resumen por referencia.
     */
    protected function processFile(array $file, array &$summary, ?callable $onLog): void
    {
        try {
            $json = $this->dropbox->downloadJson($file['path_lower']);

            if (! $json || ! isset($json['_id'])) {
                Log::warning('[SmdAppointmentSync] JSON sin _id o inválido', [
                    'file' => $file['path_lower'] ?? '?',
                ]);
                $summary['errores']++;

                return;
            }

            $summary[$this->processPayload($json, $file['path_lower'])]++;
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

        if (! $lock->get()) {
            Log::info('[SmdAppointmentSync] Cita ya en proceso por otro worker, se omite', [
                'external_id' => $externalId,
            ]);

            return 'sin_cambios';
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
                    $this->incoming->updateDropbox($json, $existing);
                }

                $this->incoming->cancelDropbox($externalId, $existing);
                DB::table('smd_synced_events')
                    ->where('external_id', $externalId)
                    ->update($this->withSourceUpdatedAt([
                        'raw_payload' => json_encode($json),
                        'status'      => $status,
                        'archived_at' => now(),
                        'updated_at'  => now(),
                    ], $updatedAt));
            }

            return 'cancelados';
        }

        if ($existing) {
            $this->incoming->updateDropbox($json, $existing);
            DB::table('smd_synced_events')
                ->where('external_id', $externalId)
                ->update($this->withSourceUpdatedAt([
                    'raw_payload' => json_encode($json),
                    'status'      => $status,
                    'updated_at'  => now(),
                ], $updatedAt));

            return 'actualizados';
        }

        $result = $this->incoming->processDropbox($json);

        // Un evento sin paciente es un bloqueo de agenda ("Ocupado"), no una cita.
        // Se registra igual para no re-evaluarlo en cada corrida, pero sin cita ni
        // lead: crearlos generaba personas "Paciente" y leads basura.
        if (($result['skipped'] ?? null) === 'block') {
            DB::table('smd_synced_events')->insert($this->withSourceUpdatedAt([
                'external_id'  => $externalId,
                'source_file'  => $sourceFile,
                'activity_id'  => null,
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
