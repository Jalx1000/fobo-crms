<?php

namespace Webkul\Admin\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Admin\Services\SmdStatusMapper;

class SyncDropboxAppointments extends Command
{
    protected $signature   = 'smd:sync-dropbox {--date= : Fecha YYYY-MM-DD, default=hoy} {--days=1 : Cuantos dias hacia atras revisar}';

    protected $description = 'Sincroniza citas de la carpeta Dropbox de SMD al CRM';

    public function __construct(
        private DropboxService $dropbox,
        private IncomingAppointmentService $incoming,
        private SmdStatusMapper $mapper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days     = (int) $this->option('days');
        $baseDate = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now();

        $processed = 0;
        $updated   = 0;
        $cancelled = 0;
        $skipped   = 0;
        $errors    = 0;

        for ($i = 0; $i < $days; $i++) {
            $date  = $baseDate->copy()->subDays($i)->toDateString();
            $files = $this->dropbox->listFilesForDate($date);

            $this->line("{$date} — " . count($files) . ' archivos');

            foreach ($files as $file) {
                try {
                    $json = $this->dropbox->downloadJson($file['path_lower']);

                    if (! $json || ! isset($json['_id'])) {
                        Log::warning('[SyncDropbox] JSON sin _id o invalido', [
                            'file' => $file['path_lower'] ?? '?',
                        ]);
                        $this->error("  ✗ Download fallido o JSON inválido: " . ($file['path_lower'] ?? '?'));
                        $errors++;

                        continue;
                    }

                    $externalId    = $json['_id'];
                    $isArchived    = $json['archived'] ?? false;
                    $status        = $json['status']   ?? '';
                    $shouldCancel  = $isArchived || $this->mapper->isCancelled($status);

                    $existing = DB::table('smd_synced_events')
                        ->where('external_id', $externalId)
                        ->first();

                    if ($shouldCancel) {
                        if ($existing) {
                            $this->incoming->cancelDropbox($externalId, $existing);
                            DB::table('smd_synced_events')
                                ->where('external_id', $externalId)
                                ->update([
                                    'status'      => $status,
                                    'archived_at' => now(),
                                    'updated_at'  => now(),
                                ]);
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

                        $this->incoming->updateDropbox($json, $existing);
                        DB::table('smd_synced_events')
                            ->where('external_id', $externalId)
                            ->update([
                                'raw_payload' => json_encode($json),
                                'status'      => $status,
                                'updated_at'  => now(),
                            ]);
                        $updated++;
                    } else {
                        $result = $this->incoming->processDropbox($json);
                        DB::table('smd_synced_events')->insert([
                            'external_id'  => $externalId,
                            'source_file'  => $file['path_lower'],
                            'activity_id'  => $result['activity_id'] ?? null,
                            'lead_id'      => $result['lead_id']      ?? null,
                            'raw_payload'  => json_encode($json),
                            'status'       => $status,
                            'processed_at' => now(),
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                        $processed++;
                    }

                } catch (\Throwable $e) {
                    $this->error("  ✗ {$file['path_lower']}: " . $e->getMessage());
                    $this->line("    " . collect(explode("\n", $e->getTraceAsString()))->take(6)->implode("\n    "));
                    $errors++;
                }
            }
        }

        $this->info("Creados: {$processed} | Actualizados: {$updated} | Cancelados: {$cancelled} | Sin cambios: {$skipped} | Errores: {$errors}");

        return self::SUCCESS;
    }
}
