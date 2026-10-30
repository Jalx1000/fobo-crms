<?php

namespace Webkul\Admin\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Webkul\Admin\Services\SmdAppointmentSyncService;

class SyncDropboxAppointments extends Command
{
    protected $signature = 'smd:sync-dropbox
        {--days=1 : Cuantos dias hacia atras revisar (incluye la fecha base)}
        {--date= : Fecha base YYYY-MM-DD (default: hoy)}';

    protected $description = 'Sincroniza citas de la carpeta Dropbox de SMD al CRM';

    public function handle(SmdAppointmentSyncService $service): int
    {
        $days = (int) $this->option('days');
        $baseDate = $this->option('date') ? Carbon::parse($this->option('date')) : null;

        $summary = $service->run($days, fn (string $line) => $this->line($line), $baseDate);

        $this->info(sprintf(
            'Creados: %d | Actualizados: %d | Cancelados: %d | Sin cambios: %d | Obsoletos: %d | En proceso: %d | Errores: %d',
            $summary['creados'],
            $summary['actualizados'],
            $summary['cancelados'],
            $summary['sin_cambios'],
            $summary['obsoletos'],
            $summary['en_proceso'],
            $summary['errores'],
        ));

        return self::SUCCESS;
    }
}
