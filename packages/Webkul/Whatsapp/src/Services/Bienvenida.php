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

        /*
         * El encabezado con el logo.
         *
         * 🔴 Lo decide la PLANTILLA, no la config. Meta valida el mensaje contra la
         * estructura exacta con la que la plantilla fue aprobada, y los dos desajustes
         * dan el mismo error `(#132012) Parameter format does not match format in the
         * created template`:
         *
         *   plantilla CON header IMAGE + envío sin imagen  → 132012
         *   plantilla SIN header        + envío con imagen → 132012
         *
         * Por eso no basta con tener la URL configurada: se manda sólo si la plantilla
         * declara un header de media. Así, configurar el logo antes de que la plantilla
         * nueva esté aprobada no rompe el saludo que hoy sale bien.
         */
        $header = $this->headerDeLogo($plantilla, (string) ($config['logo'] ?? ''));

        if ($header === false) {
            // La plantilla pide imagen y no hay URL: el envío saldría rechazado con un
            // 132012 que habla de "formato de parámetros" y no de un logo faltante.
            Log::warning('WhatsApp: la plantilla de bienvenida pide un encabezado de imagen y no hay logo configurado', [
                'template' => $plantilla['name'],
                'formato'  => $plantilla['header']['format'] ?? null,
            ]);

            return false;
        }

        $mensaje = DB::transaction(function () use ($telefono, $nombre, $plantilla, $variables, $header) {
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
                'payload' => array_filter([
                    'template'  => $plantilla['name'],
                    'language'  => $plantilla['language'],
                    'variables' => $variables,
                    // Sólo si la plantilla lo declara: SendMessage lo convierte en
                    // TemplateHeader y el gateway lo manda como componente `header`.
                    'header' => $header,
                ], fn ($v) => $v !== null),
            ]);
        });

        SendMessage::dispatch($mensaje->id)->onQueue(config('whatsapp.queue'));

        return true;
    }

    /**
     * El encabezado que hay que mandar con esta plantilla.
     *
     * Devuelve:
     *   null  → la plantilla no declara header de media; el logo configurado se ignora.
     *   false → lo declara pero no hay URL; no hay nada que mandar que Meta acepte.
     *   array → el nodo listo para el payload.
     *
     * El tercer estado existe porque "no hay header" y "falta el header que se pidió"
     * necesitan caminos distintos: el primero manda el saludo igual, el segundo tiene
     * que frenar y dejar dicho por qué.
     *
     * @param  array{header?:?array}  $plantilla
     * @return array{format:string, link:string}|false|null
     */
    protected function headerDeLogo(array $plantilla, string $logo): array|false|null
    {
        $header = $plantilla['header'] ?? null;

        // Sin header, o un header TEXT con variables: no es donde va un logo. Un TEXT
        // con {{n}} en la bienvenida no está contemplado a propósito — la plantilla la
        // armamos nosotros, y si algún día lo tiene, esto se entera acá y no en Meta.
        if (! $header || ! ($header['media'] ?? false)) {
            return null;
        }

        $logo = trim($logo);

        if ($logo === '') {
            return false;
        }

        return [
            // El formato sale de la PLANTILLA y no de la extensión del archivo: si Meta
            // la aprobó como IMAGE, el nodo tiene que decir image aunque la URL termine
            // en algo raro.
            'format' => (string) $header['format'],
            'link'   => $logo,
        ];
    }
}
