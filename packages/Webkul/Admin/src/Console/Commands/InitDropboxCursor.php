<?php

namespace Webkul\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\DropboxService;

class InitDropboxCursor extends Command
{
    protected $signature   = 'smd:dropbox-init-cursor';

    protected $description = 'Inicializa el cursor de Dropbox para el webhook (ejecutar una vez antes del primer webhook)';

    public function __construct(private DropboxService $dropbox)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $folder = config('smd.dropbox.appointments_folder', '/smd-events');

        $this->info("Obteniendo cursor para: {$folder}");

        $cursor = $this->dropbox->getInitialCursor($folder);

        if (! $cursor) {
            $this->error('No se pudo obtener el cursor. Verifica el DROPBOX_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'dropbox_cursor'],
            ['value' => $cursor, 'updated_at' => now()]
        );

        $this->info('Cursor guardado: ' . substr($cursor, 0, 40) . '...');
        $this->info('El webhook ya puede recibir notificaciones.');

        return self::SUCCESS;
    }
}
