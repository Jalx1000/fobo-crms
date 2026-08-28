<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Jobs\DescargarMediaEntrante;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Encola la descarga de archivos entrantes que quedaron sin bajar.
 *
 * Existe porque el id del archivo se venía guardando en el payload crudo desde
 * el principio: los mensajes viejos tienen el puntero aunque nunca se haya
 * descargado nada. También sirve como red si una tanda falla y hay que
 * reintentarla en bloque.
 */
class DescargarMediaHistorica extends Command
{
    protected $signature = 'crm:descargar-media-historica
                            {--dry-run : Muestra qué se encolaría sin encolar nada}
                            {--limite=200 : Tope de mensajes por corrida}';

    protected $description = 'Descarga los archivos de mensajes entrantes que no se bajaron';

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');
        $tipos = (array) config('whatsapp.inbound_media.tipos', []);

        $pendientes = MessageProxy::modelClass()::query()
            ->where('direction', 'inbound')
            ->whereIn('type', $tipos)
            ->whereNull('media_path')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->limit((int) $this->option('limite'))
            ->get()
            // Sin id en el payload no hay nada que pedirle al proveedor: son
            // mensajes anteriores a que se guardara el crudo, o de otro gateway.
            ->filter(fn ($m) => filled(data_get($m->payload, ($m->payload['type'] ?? $m->type).'.id')));

        if ($pendientes->isEmpty()) {
            $this->info('No hay archivos pendientes de descarga.');

            return self::SUCCESS;
        }

        $this->info($simular
            ? "SIMULACIÓN — se encolarían {$pendientes->count()} descargas"
            : "Encolando {$pendientes->count()} descargas");

        foreach ($pendientes as $mensaje) {
            $this->line("  #{$mensaje->id} · {$mensaje->type} · conversación {$mensaje->conversation_id}");

            if (! $simular) {
                DescargarMediaEntrante::dispatch($mensaje->id)->onQueue(config('whatsapp.queue'));
            }
        }

        if (! $simular) {
            $this->newLine();
            $this->comment('Seguí el avance con: php artisan queue:failed');
        }

        return self::SUCCESS;
    }
}
