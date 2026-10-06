<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * El saludo a un contacto nuevo.
 *
 * Va a un número con el que nunca hablamos, así que cae fuera de la ventana de 24 h de
 * WhatsApp y sólo puede salir como PLANTILLA APROBADA por Meta. Eso tiene dos
 * consecuencias que mandan el diseño de esta clase:
 *
 *  1. Mientras la plantilla no exista o no esté aprobada, no hay nada que mandar. Se
 *     registra el motivo y se devuelve false, en vez de encolar un mensaje que el
 *     gateway va a rechazar.
 *  2. Nunca puede voltear el alta del contacto. Crear el cliente es el trabajo;
 *     saludarlo es un extra. Quien llama envuelve en try/catch, y acá además no se
 *     lanza nada: todos los caminos devuelven un booleano.
 */
class Bienvenida
{
    public function __construct(
        protected TemplateCatalog $catalogo,
        protected ConversationResolver $conversaciones,
    ) {}

    /**
     * Encola el saludo. Devuelve si quedó encolado.
     */
    public function enviarA(string $telefono, ?string $nombre = null): bool
    {
        $config = (array) config('whatsapp.bienvenida');

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        if (! trim($telefono)) {
            return false;
        }

        $plantilla = $this->catalogo->find((string) $config['template'], (string) $config['language']);

        if (! $plantilla) {
            /*
             * Se registra una vez por alta y con el nombre buscado: el síntoma que esto
             * evita es "el saludo no llega" sin ninguna pista, cuando la causa real es
             * que la plantilla todavía no está aprobada o se llama distinto en Meta.
             */
            Log::warning('WhatsApp: no hay plantilla de bienvenida aprobada', [
                'template' => $config['template'],
                'language' => $config['language'],
            ]);

            return false;
        }

        /*
         * Las variables por posición, como las espera Meta. Sólo se mandan las que la
         * plantilla declara: una de más hace que Meta rechace el mensaje entero.
         */
        $variables = array_slice(
            [trim((string) $nombre) ?: 'cliente'],
            0,
            max(0, (int) ($plantilla['variables'] ?? 0)),
        );

        $mensaje = DB::transaction(function () use ($telefono, $nombre, $plantilla, $variables) {
            $conversacion = $this->conversaciones->findOrCreate($telefono, $nombre);

            return MessageProxy::modelClass()::create([
                'conversation_id' => $conversacion->id,
                'direction'       => 'outbound',
                'sender'          => 'sistema',
                'type'            => 'template',
                // Lo legible para el hilo; el envío real usa el payload.
                'body'            => $this->catalogo->render($plantilla['body'], $variables),
                'status'          => 'queued',
                /*
                 * El payload se escribe ANTES del envío: es lo que lee SendMessage para
                 * armar el request y lo que lee un reintento, así que tiene que bastar
                 * por sí solo.
                 */
                'payload' => [
                    'template'  => $plantilla['name'],
                    'language'  => $plantilla['language'],
                    'variables' => $variables,
                ],
            ]);
        });

        SendMessage::dispatch($mensaje->id)->onQueue(config('whatsapp.queue'));

        return true;
    }
}
