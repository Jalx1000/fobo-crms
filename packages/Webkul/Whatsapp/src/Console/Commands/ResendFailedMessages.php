<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Reenvía los mensajes que no salieron por una caída del canal.
 *
 * El 29/09/2026 a las 17:12 el token de Meta perdió los permisos sobre el
 * número y durante 17 h cada envío murió con "Object with ID ... does not
 * exist". Quedaron 77 respuestas sin salir, de 52 clientes.
 *
 * Reencola en vez de reimplementar el envío: SendMessage sólo toca los mensajes
 * en `queued` y ya sabe mandar cada tipo con el payload guardado, así que un
 * interactive vuelve a salir como interactive y no como su texto plano.
 *
 * Dos filtros, y son el corazón de esto:
 *
 * 1. Si el cliente escribió DESPUÉS del mensaje que falló, no se manda. Esa
 *    respuesta quedó fuera de contexto: contestaba a algo que la conversación
 *    ya dejó atrás. De los 77, 22 están así — uno de ellos repetía tres veces
 *    el mismo menú de bienvenida.
 * 2. Fuera de la ventana de 24 h no se manda: Meta lo rechaza con 131047 y sólo
 *    se puede retomar con una plantilla aprobada.
 */
class ResendFailedMessages extends Command
{
    protected $signature = 'whatsapp:reenviar-pendientes
        {--desde= : Sólo mensajes fallados desde esta fecha (Y-m-d H:i:s)}
        {--error= : Filtrar por texto del error, para acotar a una caída concreta}
        {--pausa=2 : Segundos entre envíos}
        {--excluir= : Ids de conversación a saltear, separados por coma}
        {--todos : Mandar todos los pendientes de cada charla, no sólo el último}
        {--dry-run : Mostrar qué se reenviaría, sin mandar nada}';

    protected $description = 'Reenvía los mensajes salientes que fallaron y siguen teniendo sentido';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');
        $pausa = max(0, (int) $this->option('pausa'));

        $fallados = MessageProxy::modelClass()::query()
            ->with('conversation')
            ->where('direction', 'outbound')
            ->where('status', 'failed')
            ->when($this->option('desde'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($this->option('error'), fn ($q, $e) => $q->where('error', 'like', '%'.$e.'%'))
            ->orderBy('id')
            ->get();

        if ($fallados->isEmpty()) {
            $this->info('No hay mensajes fallados que coincidan.');

            return self::SUCCESS;
        }

        $mandar = [];
        $viejos = 0;
        $fueraDeVentana = 0;

        foreach ($fallados as $mensaje) {
            if ($this->clienteSiguioHablando($mensaje)) {
                $viejos++;

                continue;
            }

            if (! $this->dentroDeVentana($mensaje)) {
                $fueraDeVentana++;

                continue;
            }

            $mandar[] = $mensaje;
        }

        $excluidas = array_filter(array_map('intval', explode(',', (string) $this->option('excluir'))));

        if ($excluidas) {
            $mandar = array_values(array_filter($mandar, fn ($m) => ! in_array((int) $m->conversation_id, $excluidas, true)));
        }

        $repetidos = 0;

        if (! $this->option('todos')) {
            /*
             * Uno por conversación: el último.
             *
             * Mandar todos los pendientes de una misma charla produce secuencias
             * que no se entienden. Medido sobre esta caída: a un cliente le
             * habrían llegado dos veces "¿podrías indicarme tu edad?" y justo
             * después un asesor diciéndole que ya no hay promociones; a otro, el
             * mismo saludo de bienvenida duplicado.
             *
             * El último mensaje es el que refleja dónde quedó la conversación.
             */
            $ultimos = [];

            foreach ($mandar as $m) {
                $ultimos[$m->conversation_id] = $m;
            }

            $repetidos = count($mandar) - count($ultimos);
            $mandar = array_values($ultimos);
        }

        $this->line('Fallados que coinciden: '.$fallados->count());
        $this->line('  se reenvían:                       '.count($mandar));
        $this->line('  descartados, quedaron sin contexto: '.$viejos);
        $this->line('  fuera de la ventana de 24 h:        '.$fueraDeVentana);
        $this->line('  anteriores de la misma charla:      '.$repetidos);
        $this->newLine();

        $this->table(
            ['#', 'Conv', 'Contacto', 'Tipo', 'Falló', 'Contenido'],
            array_map(fn ($m) => [
                $m->id,
                $m->conversation_id,
                mb_strimwidth((string) ($m->conversation->wa_name ?? $m->conversation->wa_phone ?? '—'), 0, 18, '…'),
                $m->type,
                substr((string) $m->created_at, 5, 11),
                mb_strimwidth(preg_replace('/\s+/', ' ', (string) $m->body), 0, 42, '…'),
            ], $mandar)
        );

        if ($seco) {
            $this->newLine();
            $this->info('Simulación: se reenviarían '.count($mandar).'. Corré sin --dry-run para mandarlos.');

            return self::SUCCESS;
        }

        $this->newLine();
        $barra = $this->output->createProgressBar(count($mandar));
        $barra->start();

        $ok = 0;

        foreach ($mandar as $mensaje) {
            // SendMessage sólo actúa sobre los que están en `queued`.
            $mensaje->update(['status' => 'queued', 'error' => null]);

            SendMessage::dispatchSync($mensaje->id);

            if ($mensaje->fresh()->status === 'sent') {
                $ok++;
            }

            $barra->advance();

            if ($pausa) {
                sleep($pausa);
            }
        }

        $barra->finish();
        $this->newLine(2);

        $this->info($ok.' de '.count($mandar).' salieron.');

        if ($ok < count($mandar)) {
            $this->warn('Los que no salieron quedan en `failed` con su error, para mirarlos uno por uno.');
        }

        return self::SUCCESS;
    }

    /**
     * ¿El cliente escribió después de que este mensaje fallara?
     *
     * Si escribió, la respuesta que no salió ya no corresponde: contestaba a un
     * punto anterior de la conversación. Vale más que el agente conteste al
     * último mensaje que mandar una respuesta vieja.
     */
    private function clienteSiguioHablando($mensaje): bool
    {
        return DB::table('whatsapp_messages')
            ->where('conversation_id', $mensaje->conversation_id)
            ->where('direction', 'inbound')
            ->where('id', '>', $mensaje->id)
            ->exists();
    }

    private function dentroDeVentana($mensaje): bool
    {
        $ultimo = $mensaje->conversation?->last_inbound_at;

        return $ultimo && $ultimo->diffInHours(now()) < 24;
    }
}
