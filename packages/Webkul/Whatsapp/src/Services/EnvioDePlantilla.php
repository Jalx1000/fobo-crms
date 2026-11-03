<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Mandar una plantilla aprobada a un número, creando la conversación si hace falta.
 *
 * Lo usan la bienvenida y el recordatorio de recompra: los dos salen a conversaciones
 * sin ventana de 24 h abierta, que es el único caso donde Meta obliga a usar una
 * plantilla, y los dos tropiezan con las mismas piedras.
 *
 * 🔴 La piedra principal: Meta valida el mensaje contra la estructura EXACTA con la
 * que la plantilla fue aprobada, y los dos desajustes dan el mismo error
 * `(#132012) Parameter format does not match format in the created template`:
 *
 *   plantilla CON header de media + envío sin imagen → 132012
 *   plantilla SIN header          + envío con imagen → 132012
 *
 * Por eso el encabezado se decide leyendo lo que la plantilla DECLARA, nunca lo que
 * la configuración tenga ganas de mandar.
 */
class EnvioDePlantilla
{
    public function __construct(
        protected TemplateCatalog $catalogo,
        protected ConversationResolver $conversaciones,
    ) {}

    /**
     * Encola el envío. Devuelve el mensaje creado, o null con el motivo en el log.
     *
     * Nunca lanza: quien llama está haciendo otra cosa —dar de alta un contacto,
     * recorrer una lista de recordatorios— y un envío que falla no puede voltear eso.
     *
     * @param  array<int, string>  $variables  Por posición, como las espera Meta.
     * @param  ?string  $imagen  URL pública del encabezado, si la plantilla lo pide.
     * @param  bool  $abrirPedido  false para no abrirle un pedido vacío al cliente si
     *                             no tiene uno (ver LeadOpener::forPerson).
     */
    public function enviar(
        string $telefono,
        ?string $nombre,
        string $template,
        string $language,
        array $variables = [],
        ?string $imagen = null,
        string $motivo = 'plantilla',
        bool $abrirPedido = true,
    ): ?object {
        if (! trim($telefono)) {
            return null;
        }

        $plantilla = $this->catalogo->find($template, $language);

        if (! $plantilla) {
            /*
             * Con el nombre buscado adentro: el síntoma que esto evita es "no llega"
             * sin ninguna pista, cuando la causa real es que la plantilla todavía no
             * está aprobada o se llama distinto en Meta.
             */
            Log::warning("WhatsApp: no hay plantilla aprobada para {$motivo}", [
                'template' => $template,
                'language' => $language,
            ]);

            return null;
        }

        // Sólo las que la plantilla declara: una variable de más hace que Meta
        // rechace el mensaje entero.
        $variables = array_slice(
            array_values($variables),
            0,
            max(0, (int) ($plantilla['variables'] ?? 0)),
        );

        $header = $this->header($plantilla, (string) $imagen);

        if ($header === false) {
            Log::warning("WhatsApp: la plantilla de {$motivo} pide un encabezado de imagen y no hay URL", [
                'template' => $template,
                'formato'  => $plantilla['header']['format'] ?? null,
            ]);

            return null;
        }

        $mensaje = DB::transaction(function () use ($telefono, $nombre, $plantilla, $variables, $header, $abrirPedido) {
            $conversacion = $this->conversaciones->findOrCreate($telefono, $nombre, $abrirPedido);

            return MessageProxy::modelClass()::create([
                'conversation_id' => $conversacion->id,
                'direction'       => 'outbound',
                'sender'          => 'sistema',
                'type'            => 'template',
                // Lo legible para el hilo; el envío real usa el payload.
                'body'   => $this->catalogo->render($plantilla['body'], $variables),
                'status' => 'queued',
                /*
                 * El payload se escribe ANTES del envío: es lo que lee SendMessage para
                 * armar el request y lo que lee un reintento, así que tiene que bastar
                 * por sí solo.
                 */
                'payload' => array_filter([
                    'template'  => $plantilla['name'],
                    'language'  => $plantilla['language'],
                    'variables' => $variables,
                    'header'    => $header,
                ], fn ($v) => $v !== null),
            ]);
        });

        SendMessage::dispatch($mensaje->id)->onQueue(config('whatsapp.queue'));

        return $mensaje;
    }

    /**
     * El encabezado que hay que mandar con esta plantilla.
     *
     *   null  → la plantilla no declara header de media; la imagen configurada se ignora.
     *   false → lo declara pero no hay URL; no hay nada que mandar que Meta acepte.
     *   array → el nodo listo para el payload.
     *
     * El tercer estado existe porque "no hay header" y "falta el header que se pidió"
     * necesitan caminos distintos: el primero manda el mensaje igual, el segundo tiene
     * que frenar y dejar dicho por qué.
     *
     * @return array{format:string, link:string}|false|null
     */
    protected function header(array $plantilla, string $imagen): array|false|null
    {
        $header = $plantilla['header'] ?? null;

        if (! $header || ! ($header['media'] ?? false)) {
            return null;
        }

        $imagen = trim($imagen);

        if ($imagen === '') {
            return false;
        }

        return [
            // El formato sale de la PLANTILLA y no de la extensión del archivo: si Meta
            // la aprobó como IMAGE, el nodo tiene que decir image aunque la URL termine
            // en algo raro.
            'format' => (string) $header['format'],
            'link'   => $imagen,
        ];
    }
}
