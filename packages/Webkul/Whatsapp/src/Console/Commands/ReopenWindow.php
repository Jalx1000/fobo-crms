<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\TemplateCatalog;
use Webkul\Whatsapp\Support\ConversationCity;

/**
 * Manda una plantilla a los contactos cuya ventana de 24 h ya venció.
 *
 * Fuera de esa ventana WhatsApp sólo acepta plantillas aprobadas, así que es la
 * única forma de volver a escribirle a alguien que dejó de responder.
 *
 * DOS CUIDADOS QUE NO SON OPCIONALES:
 *
 *  - Mandar plantillas a gente que no interactúa hace semanas es exactamente lo
 *    que baja la calificación de calidad del número en Meta. Por eso existe
 *    --max-dias: por defecto no toca a nadie con más de 30 días de silencio.
 *  - La plantilla NO reabre la ventana por sí sola. La reabre la RESPUESTA del
 *    cliente. Por eso conviene una con botones: responder es un toque.
 */
class ReopenWindow extends Command
{
    protected $signature = 'whatsapp:reabrir-ventana
        {plantilla : Nombre de la plantilla aprobada en Meta}
        {--idioma=es : Código de idioma de la plantilla}
        {--sin-ciudad : Sólo contactos que no tienen ciudad resuelta}
        {--excluir= : Ids de conversación a saltar, separados por coma}
        {--max-dias=30 : No escribir a quien lleva más días en silencio}
        {--limite=0 : Cortar después de N envíos (0 = sin límite)}
        {--dry-run : Mostrar a quién se le escribiría, sin enviar}';

    protected $description = 'Envía una plantilla a los contactos con la ventana de 24 h vencida';

    public function handle(GatewayManager $gateways, TemplateCatalog $catalog): int
    {
        $seco = (bool) $this->option('dry-run');
        $plantilla = (string) $this->argument('plantilla');
        $idioma = (string) $this->option('idioma');

        /*
         * Se resuelve la plantilla ANTES de recorrer a nadie: si no existe o no
         * está aprobada, mejor enterarse acá que después de 14 mensajes en
         * "failed". Y se necesita su cuerpo para guardar el TEXTO que leyó el
         * cliente, no el nombre técnico — el hilo mostraba "consulta_pendiente"
         * donde tenía que mostrar el mensaje.
         */
        $definicion = $catalog->find($plantilla, $idioma);

        if (! $definicion) {
            $this->error("La plantilla '{$plantilla}' ({$idioma}) no existe o no está aprobada en Meta.");

            return self::FAILURE;
        }
        $maxDias = (int) $this->option('max-dias');
        $limite = (int) $this->option('limite');

        $excluidas = collect(explode(',', (string) $this->option('excluir')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->all();

        $gateway = $gateways->active();

        if (! $gateway->capabilities()->canSend('template')) {
            $this->error('El canal activo no puede enviar plantillas.');

            return self::FAILURE;
        }

        $query = ConversationProxy::modelClass()::query()
            ->with('person')
            ->whereNotNull('wa_phone')
            ->whereNotNull('last_inbound_at');

        if ($this->option('sin-ciudad')) {
            $sinCiudad = ConversationCity::noneEquivalentPipelineIds();

            $query->where(function ($q) use ($sinCiudad) {
                $q->whereRaw(ConversationCity::sql().' is null');

                if ($sinCiudad) {
                    $q->orWhereRaw(
                        ConversationCity::sql().' in ('.implode(',', array_fill(0, count($sinCiudad), '?')).')',
                        $sinCiudad
                    );
                }
            });
        }

        if ($excluidas) {
            $query->whereNotIn('id', $excluidas);
        }

        $horas = $gateway->messagingWindowHours();
        $enviados = 0;
        $saltados = 0;

        $this->line('Plantilla: '.$plantilla.' ('.$idioma.')'.($seco ? '   — SIMULACIÓN' : ''));

        if ($excluidas) {
            $this->line('Excluidas: '.implode(', ', $excluidas));
        }

        $this->newLine();

        foreach ($query->orderByDesc('last_inbound_at')->get() as $conversacion) {
            $ventana = MessagingWindow::compute($horas, $conversacion->last_inbound_at);

            // Ventana abierta: se le puede escribir libre, gastar una plantilla
            // sería tirar dinero y saturar al cliente.
            if ($ventana->open) {
                continue;
            }

            $dias = (int) now()->diffInDays($conversacion->last_inbound_at);

            if ($maxDias > 0 && $dias > $maxDias) {
                $saltados++;

                continue;
            }

            $nombre = $conversacion->person->name ?? $conversacion->wa_name ?? 'Hola';

            $this->line(sprintf('  %-5d %-15s %-26s %2dd', $conversacion->id, $conversacion->wa_phone, mb_substr($nombre, 0, 26), $dias));

            if (! $seco) {
                $mensaje = MessageProxy::modelClass()::create([
                    'conversation_id' => $conversacion->id,
                    'direction'       => 'outbound',
                    'type'            => 'template',
                    // Lo que el cliente lee, igual que hace el composer del inbox.
                    'body'            => $catalog->render($definicion['body'], [$nombre]),
                    'payload'         => [
                        'template'  => $plantilla,
                        'language'  => $idioma,
                        'variables' => [$nombre],
                    ],
                    'status' => 'queued',
                    'sender' => 'human',
                ]);

                SendMessage::dispatch($mensaje->id)->onQueue(config('whatsapp.queue'));
            }

            $enviados++;

            if ($limite > 0 && $enviados >= $limite) {
                $this->warn("  (corte por --limite={$limite})");

                break;
            }
        }

        $this->newLine();
        $this->info("Enviados: {$enviados}".($saltados ? "   ·   Saltados por más de {$maxDias} días: {$saltados}" : ''));

        return self::SUCCESS;
    }
}
