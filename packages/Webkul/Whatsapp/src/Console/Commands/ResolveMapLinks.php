<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Jobs\ResolverEnlaceDeMapa;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\EnlaceDeMapa;

/**
 * Recupera las coordenadas de los links de Maps que ya están guardados.
 *
 * Los mensajes nuevos se resuelven solos al entrar (IngestInboundPayload); esto
 * es para los que llegaron antes, que son direcciones de clientes reales que el
 * CRM tenía y no podía leer.
 */
class ResolveMapLinks extends Command
{
    protected $signature = 'whatsapp:resolve-map-links
                            {--limit=200 : Cuántos mensajes revisar, del más nuevo al más viejo}
                            {--dry-run : Sólo mostrar, sin guardar}';

    protected $description = 'Saca coordenadas de los links de Google Maps que los clientes ya mandaron';

    public function handle(): int
    {
        $mensajes = MessageProxy::modelClass()::where('direction', 'inbound')
            ->where(fn ($q) => $q
                ->where('body', 'like', '%goo.gl%')
                ->orWhere('body', 'like', '%google.com/maps%')
                ->orWhere('body', 'like', '%g.co/%'))
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get()
            // Los que ya tienen el nodo no se vuelven a pedir: cada uno es una
            // llamada a Google.
            ->reject(fn ($m) => isset(((array) $m->payload)['location']));

        if ($mensajes->isEmpty()) {
            $this->info('No quedan links de mapa sin resolver.');

            return self::SUCCESS;
        }

        $this->info("Links sin resolver: {$mensajes->count()}");

        $resueltos = 0;

        foreach ($mensajes as $mensaje) {
            $punto = EnlaceDeMapa::coordenadas($mensaje->body);

            if (! $punto) {
                $this->line("  #{$mensaje->id}  <fg=yellow>sin coordenadas</>");

                continue;
            }

            $this->line(sprintf(
                '  #%d  <fg=green>%.7f, %.7f</>  %s',
                $mensaje->id,
                $punto['latitude'],
                $punto['longitude'],
                $punto['name'] ?? '',
            ));

            if (! $this->option('dry-run')) {
                (new ResolverEnlaceDeMapa($mensaje->id))->handle();
            }

            $resueltos++;
        }

        $this->newLine();
        $this->info($this->option('dry-run')
            ? "Se resolverían $resueltos (nada guardado: --dry-run)."
            : "Resueltos y guardados: $resueltos.");

        return self::SUCCESS;
    }
}
