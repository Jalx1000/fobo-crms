<?php

namespace Webkul\Whatsapp\Gateways\CloudApi;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Webkul\Whatsapp\Gateways\BaseGateway;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Gateways\Dto\DownloadedMedia;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\StatusUpdate;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Gateways\MediaDemasiadoGrande;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\PhoneNumber;

/**
 * WhatsApp Cloud API (Meta). Addresses by phone, returns a wamid, batches
 * events as entry[].changes[].value and reports delivery receipts.
 */
class CloudApiGateway extends BaseGateway
{
    public function key(): string
    {
        return 'cloud_api';
    }

    public function capabilities(): Capabilities
    {
        // Only what is implemented today. Grows as sendMedia lands.
        /*
        | Lo que este driver IMPLEMENTA, verificado tipo por tipo. No lo que la
        | Cloud API soporta en teoría.
        |
        | Esta lista enciende y apaga controles del inbox: agregar algo acá antes
        | de que funcione ya dejó una vez un botón de adjuntar conectado a un
        | mock, que decía "enviado" sin enviar nada. Se toca al final, nunca al
        | principio.
        */
        return new Capabilities(
            send: ['text', 'reply', 'emoji', 'image', 'video', 'audio', 'document', 'location', 'interactive', 'template'],
            receive: ['text', 'image', 'audio', 'video', 'document', 'sticker', 'location', 'contact', 'interactive'],
        );
    }

    public function webhookSecurity(): WebhookSecurity
    {
        return WebhookSecurity::SIGNATURE;
    }

    public function messagingWindowHours(): ?int
    {
        return 24;
    }

    /**
     * Meta's subscription handshake. Query keys arrive as hub.mode /
     * hub.verify_token / hub.challenge; PHP turns the dots into underscores.
     */
    public function verifyWebhook(Request $request): ?Response
    {
        if ($request->query('hub_mode') !== 'subscribe') {
            return null;
        }

        $expected = (string) ($this->config['verify_token'] ?? '');
        $token = (string) $request->query('hub_verify_token', '');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            return null;
        }

        return response((string) $request->query('hub_challenge', ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function authenticateWebhook(Request $request): WebhookAuth
    {
        $secret = (string) ($this->config['app_secret'] ?? '');

        if ($secret === '') {
            return WebhookAuth::fail('WHATSAPP_APP_SECRET is not configured.');
        }

        if (! $request->hasHeader('X-Hub-Signature-256')) {
            return WebhookAuth::fail('Missing X-Hub-Signature-256 header.');
        }

        if (! $this->hmacMatches($request, 'X-Hub-Signature-256', $secret, 'sha256', 'sha256=')) {
            return WebhookAuth::fail('X-Hub-Signature-256 does not match the payload.');
        }

        return WebhookAuth::ok();
    }

    public function parseWebhook(array $payload): InboundBatch
    {
        $messages = [];
        $statuses = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                $profileName = $value['contacts'][0]['profile']['name'] ?? null;

                foreach ($value['messages'] ?? [] as $message) {
                    if (empty($message['id']) || empty($message['from'])) {
                        continue;
                    }

                    $type = $this->normalizeType($message['type'] ?? 'unknown');
                    $archivo = $this->extractMedia($message, $type);

                    $messages[] = new InboundMessage(
                        providerMessageId: (string) $message['id'],
                        contact: new ContactIdentity(
                            phone: (string) $message['from'],
                            name: $profileName,
                        ),
                        type: $type,
                        body: $this->extractBody($message, $type),
                        replyToProviderId: $message['context']['id'] ?? null,
                        raw: $message,
                        mediaId: $archivo['id'],
                        mediaMime: $archivo['mime'],
                        mediaFilename: $archivo['filename'],
                    );
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    if (empty($status['id']) || empty($status['status'])) {
                        continue;
                    }

                    $statuses[] = new StatusUpdate((string) $status['id'], (string) $status['status']);
                }
            }
        }

        return new InboundBatch($messages, $statuses);
    }

    /**
     * Botones de respuesta rápida o lista, nativos.
     *
     * Botones y lista comparten `body`, `header` y `footer`; solo difieren en el
     * nodo `action`, que es la única parte donde la distinción existe de verdad.
     */
    public function sendInteractive(
        Conversation $conversation,
        OutboundInteractive $interactive,
        ?string $replyToProviderId = null,
    ): SendResult {
        $node = ['body' => ['text' => $interactive->body]];

        if ($interactive->header) {
            $node['header'] = ['type' => 'text', 'text' => $interactive->header];
        }

        if ($interactive->footer) {
            $node['footer'] = ['text' => $interactive->footer];
        }

        if ($interactive->kind === OutboundInteractive::BUTTONS) {
            $node['type'] = 'button';
            $node['action'] = [
                'buttons' => array_map(fn (array $option) => [
                    'type'  => 'reply',
                    'reply' => ['id' => $option['id'], 'title' => $option['title']],
                ], $interactive->options),
            ];
        } else {
            $node['type'] = 'list';
            $node['action'] = [
                'button'   => $interactive->listButton,
                'sections' => [[
                    'rows' => array_map(fn (array $option) => array_filter([
                        'id'          => $option['id'],
                        'title'       => $option['title'],
                        'description' => $option['description'] ?? null,
                    ]), $interactive->options),
                ]],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => 'interactive',
            'interactive'       => $node,
        ];

        if ($replyToProviderId) {
            $payload['context'] = ['message_id' => $replyToProviderId];
        }

        $response = Http::withToken($this->required('token'))
            ->acceptJson()
            ->post($this->endpoint('messages'), $payload);

        $response->throw();

        $wamid = $response->json('messages.0.id');

        if (! $wamid) {
            throw new RuntimeException('Cloud API did not return a message id: '.$response->body());
        }

        return new SendResult($wamid);
    }

    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ];

        if ($replyToProviderId) {
            $payload['context'] = ['message_id' => $replyToProviderId];
        }

        $response = Http::withToken($this->required('token'))
            ->acceptJson()
            ->post($this->endpoint('messages'), $payload);

        $response->throw();

        $wamid = $response->json('messages.0.id');

        if (! $wamid) {
            throw new RuntimeException('Cloud API did not return a message id: '.$response->body());
        }

        return new SendResult($wamid);
    }

    /**
     * Envía un archivo. Prefiere el media_id cacheado sobre el link.
     *
     * Con `media_id` el archivo ya está en el CDN de Meta y el envío es una
     * referencia; con `link`, Meta tiene que descargarlo de este servidor en el
     * momento del envío, y un CRM lento o recién reiniciado hace que la foto
     * simplemente no llegue. Por eso el link es el plan B, no el plan A.
     */
    public function sendMedia(Conversation $conversation, OutboundMedia $media): SendResult
    {
        if (! $media->tieneDestino()) {
            throw new RuntimeException('OutboundMedia sin media_id ni url.');
        }

        $objeto = filled($media->providerMediaId)
            ? ['id' => $media->providerMediaId]
            : ['link' => $media->url];

        if (filled($media->caption) && $media->kind !== 'audio') {
            $objeto['caption'] = $media->caption;
        }

        if ($media->kind === 'document' && filled($media->filename)) {
            $objeto['filename'] = $media->filename;
        }

        return $this->enviar($conversation, [
            'type'         => $media->kind,
            $media->kind   => $objeto,
        ]);
    }

    public function sendLocation(Conversation $conversation, OutboundLocation $location): SendResult
    {
        return $this->enviar($conversation, [
            'type'     => 'location',
            'location' => array_filter([
                'latitude'  => $location->latitude,
                'longitude' => $location->longitude,
                'name'      => $location->name,
                'address'   => $location->address,
            ], fn ($v) => $v !== null),
        ]);
    }

    public function sendTemplate(Conversation $conversation, OutboundTemplate $template): SendResult
    {
        $nodo = [
            'name'     => $template->name,
            'language' => ['code' => $template->language],
        ];

        // Meta rechaza `components` con parámetros vacíos; sin parámetros no va.
        if ($template->params) {
            $nodo['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(
                    fn (string $valor) => ['type' => 'text', 'text' => $valor],
                    $template->params,
                ),
            ]];
        }

        return $this->enviar($conversation, [
            'type'     => 'template',
            'template' => $nodo,
        ]);
    }

    /**
     * Sube el archivo a Meta y devuelve su media_id.
     *
     * Meta lo retiene ~30 días; el llamador guarda la fecha de vencimiento y
     * vuelve a subirlo antes de que caduque.
     */
    public function uploadMedia(string $absolutePath, string $mimeType): ?string
    {
        $response = Http::withToken($this->required('token'))
            ->attach('file', file_get_contents($absolutePath), basename($absolutePath), ['Content-Type' => $mimeType])
            ->post($this->endpoint('media'), [
                'messaging_product' => 'whatsapp',
                'type'              => $mimeType,
            ]);

        $response->throw();

        return $response->json('id');
    }

    /**
     * Descarga un archivo entrante. Dos pasos, y el id se resuelve cada vez.
     *
     * Meta no expone una URL estable: primero hay que pedirle metadatos al id
     * para obtener una URL temporal, y recién después descargarla con el token.
     * Esa URL vive pocos minutos, así que cachearla haría que todo reintento
     * fallara — por eso el segundo paso nunca se separa del primero.
     *
     * El tamaño se comprueba con los metadatos ANTES de bajar el binario: no
     * tiene sentido traer 90 MB para después descartarlos.
     */
    public function downloadMedia(string $mediaId, int $maxBytes): ?DownloadedMedia
    {
        $meta = Http::withToken($this->required('token'))
            ->acceptJson()
            ->get($this->grafo($mediaId));

        // 404 del proveedor: el archivo ya no está. No es un error nuestro y no
        // tiene sentido reintentarlo.
        if ($meta->status() === 404) {
            return null;
        }

        $meta->throw();

        $url = $meta->json('url');
        $mime = $meta->json('mime_type') ?: 'application/octet-stream';
        $size = (int) $meta->json('file_size');

        if (! $url) {
            throw new RuntimeException('Cloud API no devolvió URL de descarga para el media '.$mediaId);
        }

        if ($size > 0 && $size > $maxBytes) {
            throw new MediaDemasiadoGrande($size, $maxBytes);
        }

        // La descarga del binario va al host que Meta indicó, no al endpoint de
        // la API, pero igual exige el token.
        $archivo = Http::withToken($this->required('token'))->get($url);

        $archivo->throw();

        $contenido = $archivo->body();

        // Segunda comprobación: el proveedor no siempre informa file_size.
        if (strlen($contenido) > $maxBytes) {
            throw new MediaDemasiadoGrande(strlen($contenido), $maxBytes);
        }

        return new DownloadedMedia(
            contents: $contenido,
            mime: $mime,
            size: strlen($contenido),
        );
    }

    /**
     * Parte común de todo envío: destinatario, POST y extracción del wamid.
     */
    protected function enviar(Conversation $conversation, array $payload): SendResult
    {
        $response = Http::withToken($this->required('token'))
            ->acceptJson()
            ->post($this->endpoint('messages'), array_merge([
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => PhoneNumber::digits($conversation->wa_phone),
            ], $payload));

        $response->throw();

        $wamid = $response->json('messages.0.id');

        if (! $wamid) {
            throw new RuntimeException('Cloud API did not return a message id: '.$response->body());
        }

        return new SendResult($wamid);
    }

    /**
     * Map Meta's type to ours ('contacts' is plural on the wire).
     */
    protected function normalizeType(string $type): string
    {
        return $type === 'contacts' ? 'contact' : $type;
    }

    /**
     * Punteros al archivo adjunto, si el mensaje trae uno.
     *
     * Meta anida los datos bajo una clave con el nombre del tipo
     * (`image.id`, `document.id`, …). Solo los tipos que son realmente un
     * archivo: `location` y `contact` viajan completos en el payload y no hay
     * nada que descargar.
     *
     * @return array{id: ?string, mime: ?string, filename: ?string}
     */
    protected function extractMedia(array $message, string $type): array
    {
        $conArchivo = ['image', 'video', 'audio', 'document', 'sticker'];

        if (! in_array($type, $conArchivo, true)) {
            return ['id' => null, 'mime' => null, 'filename' => null];
        }

        // La clave del payload usa el nombre original de Meta, no el
        // normalizado: por eso se lee de `$message['type']` y no de `$type`.
        $datos = $message[$message['type'] ?? $type] ?? [];

        return [
            'id'       => isset($datos['id']) ? (string) $datos['id'] : null,
            'mime'     => $datos['mime_type'] ?? null,
            // Solo los documentos traen nombre; el resto se nombra al guardar.
            'filename' => $datos['filename'] ?? null,
        ];
    }

    /**
     * Human-readable body: text for text, caption for media.
     */
    protected function extractBody(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'image', 'video', 'document', 'audio' => $message[$message['type']]['caption'] ?? null,
            'button' => $message['button']['text'] ?? null,
            // Cuando el cliente toca un botón o elige de una lista, Meta manda
            // type=interactive y el texto queda anidado bajo button_reply o
            // list_reply. Sin esto el body va null y el agente ignora el
            // mensaje: es la causa de «el agente no responde a los botones».
            'interactive' => $this->interactiveBody($message),
            default       => null,
        };
    }

    /**
     * Texto legible de una respuesta interactiva (botón o lista).
     *
     * Meta anida así:
     *   interactive.type = 'button_reply' | 'list_reply'
     *   interactive.button_reply = ['id' => ..., 'title' => ...]
     *   interactive.list_reply   = ['id' => ..., 'title' => ..., 'description' => ...]
     *
     * Se entrega el TÍTULO, no el id: el agente es un modelo de lenguaje y
     * razona sobre «De 2 dormitorios», no sobre `btn_2`. El id igual le llega,
     * aparte, en `message.interactive` — así puede enrutar por código si quiere
     * sin perder el sentido de lo que eligió la persona.
     */
    protected function interactiveBody(array $message): ?string
    {
        $interactive = $message['interactive'] ?? [];
        $reply = $interactive[$interactive['type'] ?? ''] ?? null;

        return is_array($reply) ? ($reply['title'] ?? null) : null;
    }

    protected function endpoint(string $path): string
    {
        return sprintf('%s/%s', $this->grafo($this->required('phone_number_id')), $path);
    }

    /**
     * Endpoint del grafo que NO cuelga del número de teléfono.
     *
     * Los metadatos de un archivo se piden por su id directamente
     * (`/v21.0/{media-id}`). Colgarlos del `phone_number_id` —como hace
     * `endpoint()` para enviar y subir— devuelve 400: no es la misma jerarquía.
     */
    protected function grafo(string $path): string
    {
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        return sprintf('https://graph.facebook.com/%s/%s', $version, $path);
    }
}
