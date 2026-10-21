<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Repositories\HandoffRepository;
use Webkul\Whatsapp\Services\OutboundDelivery;

/**
 * Recordatorio en voz de Sofía ~1 h antes de que se cierre la ventana de 24 h.
 *
 * SE RESUELVE SONDEANDO Y NO PROGRAMANDO UN JOB POR CONVERSACIÓN, a propósito.
 *
 * Un job agendado a `expires_at - 1h` obliga a cancelarlo y reprogramarlo cada
 * vez que el cliente escribe, el asesor toma la conversación o se deriva —tres
 * caminos donde olvidarse de cancelar manda un mensaje que no correspondía—. Y
 * las condiciones hay que revalidarlas al disparar igual, porque el estado pudo
 * cambiar entre que se agendó y que corrió.
 *
 * Sondeando, la reprogramación es automática: la ventana se deriva de
 * `last_inbound_at`, así que un mensaje nuevo del cliente mueve el momento del
 * disparo sin que nadie haga nada. Y no hay nada que cancelar: si la IA se
 * apagó o entró una derivación, la conversación simplemente deja de calificar.
 */
class RecontactarAntesDeCerrar extends Command
{
    protected $signature = 'crm:recontactar-antes-de-cerrar
                            {--dry-run : Muestra a quién se le escribiría, sin enviar}';

    protected $description = 'Manda un recordatorio antes de que expire la ventana de 24h de WhatsApp';

    public function __construct(
        protected OutboundDelivery $delivery,
        protected HandoffRepository $handoffs,
        protected GatewayManager $gateways,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('whatsapp.followup.enabled', true)) {
            $this->info('Recontacto desactivado por configuración.');

            return self::SUCCESS;
        }

        $simular = (bool) $this->option('dry-run');

        $candidatas = $this->candidatas();

        if ($candidatas->isEmpty()) {
            $this->info('Ninguna conversación en la franja de recontacto.');

            return self::SUCCESS;
        }

        $enviados = 0;
        $salteados = 0;

        foreach ($candidatas as $conversation) {
            if ($motivo = $this->noCorresponde($conversation)) {
                $this->line("  #{$conversation->id} salteada: {$motivo}");
                $salteados++;

                continue;
            }

            $texto = $this->mensajePara($conversation);

            if ($simular) {
                $this->line("  #{$conversation->id} ({$conversation->wa_phone}) -> {$texto}");
                $enviados++;

                continue;
            }

            try {
                $this->delivery->texto($conversation, $texto);

                /*
                 * Se marca al encolar y no al confirmarse la entrega: si el envío
                 * falla, reintentarlo en el próximo sondeo mandaría un segundo
                 * mensaje al cliente. Un recordatorio perdido es mejor que dos.
                 */
                $conversation->forceFill(['followup_sent_at' => now()])->save();

                $this->info("  #{$conversation->id} ({$conversation->wa_phone}) recordatorio enviado.");
                $enviados++;
            } catch (Throwable $e) {
                Log::error('Recontacto fallido', [
                    'conversation_id' => $conversation->id,
                    'error'           => $e->getMessage(),
                ]);

                $this->error("  #{$conversation->id} falló: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            '%s%s recordatorio(s), %s salteada(s).',
            $simular ? '[simulación] ' : '',
            $enviados,
            $salteados
        ));

        return self::SUCCESS;
    }

    /**
     * Conversaciones dentro de la franja de disparo, filtradas en SQL.
     *
     * Lo que se puede descartar con una condición de columna se descarta acá y
     * no en PHP: con unos miles de conversaciones, traerlas todas para mirarlas
     * una por una es una consulta que crece sola.
     */
    protected function candidatas()
    {
        $horasVentana = (int) ($this->gateways->active()->messagingWindowHours() ?? 24);
        $horasAntes = (float) config('whatsapp.followup.horas_antes', 1);

        $prefijo = DB::getTablePrefix();

        return ConversationProxy::modelClass()::query()
            ->where('ai_enabled', 1)
            ->whereNotNull('last_inbound_at')

            /*
             * Conversación no cerrada.
             *
             * Se EXCLUYE 'closed' en vez de exigir 'open', y la diferencia no es
             * cosmética: ConversationResolver guarda 'unassigned' cuando el número
             * no tiene ficha de contacto (`$person ? 'open' : 'unassigned'`).
             * Exigir 'open' habría apagado el recontacto para todos los contactos
             * desconocidos — justo los leads con más riesgo de perderse, que son
             * la razón de ser de esto.
             */
            ->where('status', '!=', 'closed')

            // Ventana todavía abierta.
            ->where('last_inbound_at', '>', now()->subHours($horasVentana))

            // Ya entró en la franja: estamos a menos de `horas_antes` del cierre.
            ->where('last_inbound_at', '<=', now()->subHours($horasVentana - $horasAntes))

            /*
             * Sin recordatorio en ESTA ventana. `followup_sent_at` anterior al
             * último mensaje del cliente pertenece a una ventana ya vencida, así
             * que no cuenta.
             */
            ->where(function ($q) use ($prefijo) {
                $q->whereNull('followup_sent_at')
                    ->orWhereRaw("{$prefijo}whatsapp_conversations.followup_sent_at < {$prefijo}whatsapp_conversations.last_inbound_at");
            })

            // La pelota tiene que estar del lado del cliente: si lo último que
            // pasó fue que el cliente escribió, el agente le debe respuesta y un
            // recordatorio sonaría absurdo.
            ->whereColumn('last_message_at', '>', 'last_inbound_at')

            ->orderBy('last_inbound_at')
            ->get();
    }

    /**
     * Las condiciones que no se pueden expresar en SQL, revalidadas al enviar.
     *
     * Devuelve null si corresponde enviar, o el motivo por el que no.
     */
    protected function noCorresponde($conversation): ?string
    {
        if (! $conversation->wa_phone) {
            return 'sin teléfono';
        }

        if ($this->handoffs->abiertoDe($conversation->id)) {
            return 'derivada a un asesor';
        }

        // `aiEffective()` combina el interruptor global con el de la
        // conversación; `ai_enabled` a secas solo mira el segundo.
        if (method_exists($conversation, 'aiEffective') && ! $conversation->aiEffective()) {
            return 'IA desactivada';
        }

        return null;
    }

    /**
     * El texto, con el nombre del contacto si se conoce.
     */
    protected function mensajePara($conversation): string
    {
        $plantilla = (string) config('whatsapp.followup.mensaje');

        $nombre = trim((string) ($conversation->wa_name ?? ''));

        if ($nombre === '') {
            // Sin nombre se quita el marcador y el espacio que lo precede, para
            // que no quede «Hola  👋» con doble espacio.
            return preg_replace('/\s*\{nombre\}/u', '', $plantilla);
        }

        // Solo el primer nombre: el perfil de WhatsApp suele traer apodos y
        // apellidos completos, y «Hola Danitza» suena mejor que el string crudo.
        $primero = explode(' ', $nombre)[0];

        return str_replace('{nombre}', $primero, $plantilla);
    }
}
