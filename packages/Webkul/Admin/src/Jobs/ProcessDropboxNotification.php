<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Admin\Services\SmdStatusMapper;

class ProcessDropboxNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;

    public int $timeout = 120;

    public function handle(
        DropboxService $dropbox,
        IncomingAppointmentService $incoming,
        SmdStatusMapper $mapper,
    ): void {
        $cursor = DB::table('settings')
            ->where('key', 'dropbox_cursor')
            ->value('value');

        if (! $cursor) {
            Log::warning('[DropboxWebhook] No hay cursor guardado. Ejecuta: php artisan smd:dropbox-init-cursor');

            return;
        }

        $processed = $updated = $cancelled = $skipped = $errors = 0;

        do {
            $result  = $dropbox->listChanges($cursor);
            $entries = $result['entries'];
            $cursor  = $result['cursor'];
            $hasMore = $result['has_more'];

            foreach ($entries as $file) {
                try {
                    $json = $dropbox->downloadJson($file['path_lower']);

                    if (! $json || ! isset($json['_id'])) {
                        $errors++;

                        continue;
                    }

                    $externalId   = $json['_id'];
                    $isArchived   = $json['archived'] ?? false;
                    $status       = $json['status']   ?? '';
                    $shouldCancel = $isArchived || $mapper->isCancelled($status);

                    $existing = DB::table('smd_synced_events')
                        ->where('external_id', $externalId)
                        ->first();

                    if ($shouldCancel) {
                        if ($existing) {
                            $incoming->cancelDropbox($externalId, $existing);
                            DB::table('smd_synced_events')
                                ->where('external_id', $externalId)
                                ->update(['status' => $status, 'archived_at' => now(), 'updated_at' => now()]);
                        }
                        $cancelled++;

                        continue;
                    }

                    if ($existing) {
                        $newHash = md5(json_encode($json));

                        if (md5($existing->raw_payload ?? '') === $newHash) {
                            $skipped++;

                            continue;
                        }

                        $incoming->updateDropbox($json, $existing);
                        DB::table('smd_synced_events')
                            ->where('external_id', $externalId)
                            ->update(['raw_payload' => json_encode($json), 'status' => $status, 'updated_at' => now()]);
                        $updated++;
                    } else {
                        $result2 = $incoming->processDropbox($json);
                        DB::table('smd_synced_events')->insert([
                            'external_id'  => $externalId,
                            'source_file'  => $file['path_lower'],
                            'activity_id'  => $result2['activity_id'] ?? null,
                            'lead_id'      => $result2['lead_id']      ?? null,
                            'raw_payload'  => json_encode($json),
                            'status'       => $status,
                            'processed_at' => now(),
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                        $processed++;
                    }

                } catch (\Throwable $e) {
                    Log::error('[DropboxWebhook] Error procesando archivo', [
                        'file'  => $file['path_lower'] ?? '?',
                        'error' => $e->getMessage(),
                    ]);
                    $errors++;
                }
            }

            DB::table('settings')->updateOrInsert(
                ['key' => 'dropbox_cursor'],
                ['value' => $cursor, 'updated_at' => now()]
            );

        } while ($hasMore);

        Log::info('[DropboxWebhook] Procesamiento completado', compact('processed', 'updated', 'cancelled', 'skipped', 'errors'));
    }
}
