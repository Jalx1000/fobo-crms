<?php

namespace Webkul\Whatsapp\Gateways\CloudApi;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Webkul\Whatsapp\Gateways\BaseGateway;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\StatusUpdate;
use Webkul\Whatsapp\Gateways\Dto\TemplateHeader;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\PhoneNumber;
use Webkul\Whatsapp\Support\UbicacionDeMensaje;

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
        // Only what is implemented today: every entry below is wired to a
        // real method, because the composer's action menu renders from this.
        return new Capabilities(
            send: ['text', 'reply', 'emoji', 'template', 'image', 'document', 'audio', 'video', 'location', 'buttons', 'list'],
            receive: ['text', 'image', 'audio', 'document', 'video', 'sticker', 'location', 'contact', 'interactive', 'button'],
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

                    $messages[] = new InboundMessage(
                        providerMessageId: (string) $message['id'],
                        contact: new ContactIdentity(
                            phone: (string) $message['from'],
                            name: $profileName,
                        ),
                        type: $type,
                        body: $this->extractBody($message, $type),
                        /*
                         * Una reacción ES una respuesta a otro mensaje, pero
                         * Meta no la manda en `context`: el destino viaja en
                         * `reaction.message_id`. Mapearlo acá deja que el hilo
                         * la muestre con la cita del mensaje reaccionado
                         * reusando el mecanismo de respuesta que ya existe, en
                         * vez de inventar un segundo camino para lo mismo.
                         */
                        replyToProviderId: $message['context']['id']
                            ?? ($message['reaction']['message_id'] ?? null),
                        raw: $message,
                    );
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    if (empty($status['id']) || empty($status['status'])) {
                        continue;
                    }

                    $statuses[] = new StatusUpdate(
                        (string) $status['id'],
                        (string) $status['status'],
                        (array) ($status['errors'] ?? []),
                    );
                }
            }
        }

        return new InboundBatch($messages, $statuses);
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

        return new SendResult($this->post($payload));
    }

    /**
     * Send an approved template.
     *
     * The only way to reach a customer once the 24h window has closed, which is
     * exactly when it matters: outside it, plain text is rejected outright.
     */
    public function sendTemplate(
        Conversation $conversation,
        string $templateKey,
        array $variables = [],
        ?string $language = null,
        ?TemplateHeader $header = null,
    ): SendResult {
        $template = [
            'name'     => $templateKey,
            'language' => ['code' => $language ?: 'es'],
        ];

        /*
         * Meta fills the placeholders BY POSITION, so the array is re-indexed
         * before it goes out: a variables array that lost its 0..n keys (an
         * unset, a filter) would silently shift {{2}} into {{1}}'s slot and
         * send the customer a message with the values swapped.
         */
        $variables = array_values($variables);

        /*
         * Los componentes tienen que reflejar la estructura EXACTA con la que
         * la plantilla fue creada, y van en el orden en que Meta los declara:
         * header y después body.
         *
         * Hasta acá sólo se emitía `body`. Una plantilla con encabezado de
         * documento —las de cotización— salía sin él y Meta rechazaba el envío
         * entero con `(#132012) Parameter format does not match format in the
         * created template`. El asesor veía "No se envió" y ninguna pista de
         * que lo que faltaba era el archivo.
         */
        $components = [];

        if ($header && $parameters = $this->headerParameters($header)) {
            $components[] = [
                'type'       => 'header',
                'parameters' => $parameters,
            ];
        }

        if ($variables) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                    $variables,
                ),
            ];
        }

        /*
         * Sin componentes NO se manda la clave: una plantilla sin variables
         * (hello_world) con `components: []` también da 132012 — sobrar un
         * componente es tan inválido como que falte.
         */
        if ($components) {
            $template['components'] = $components;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => 'template',
            'template'          => $template,
        ];

        return new SendResult($this->post($payload));
    }

    /**
     * Los parámetros del encabezado, en la forma que espera la Graph API.
     *
     * Un header de media viaja como `{type: 'document', document: {link, filename}}`
     * —el nombre del nodo se repite como tipo, que es la convención rara pero
     * obligatoria de Meta—. Uno de texto viaja como parámetros de texto.
     *
     * Devuelve [] para un header estático (TEXT sin variables): ahí Meta pone el
     * contenido fijo de la plantilla y cualquier parámetro que le mandemos es un
     * 132012.
     *
     * @return array<int, array>
     */
    protected function headerParameters(TemplateHeader $header): array
    {
        if ($header->isMedia()) {
            if (! $header->link) {
                return [];
            }

            $kind = $header->mediaKind();
            $node = ['link' => $header->link];

            // Sólo el documento lleva nombre; en imagen o video Meta lo rechaza.
            if ($kind === 'document' && $header->filename) {
                $node['filename'] = $header->filename;
            }

            return [[
                'type' => $kind,
                $kind  => $node,
            ]];
        }

        return array_map(
            fn ($value) => ['type' => 'text', 'text' => (string) $value],
            array_values($header->variables),
        );
    }

    /**
     * Send a file.
     *
     * Meta fetches it from `link`, so the URL must be publicly reachable and
     * must answer with the mime we declare here — that is what MediaController
     * exists for. Served straight off the filesystem, a voice note recorded as
     * audio/mp4 goes out as video/mp4 and WhatsApp rejects it.
     */
    public function sendMedia(
        Conversation $conversation,
        OutboundMedia $media,
        ?string $replyToProviderId = null,
    ): SendResult {
        $node = ['link' => $media->url];

        // A caption is only legal on visual media and documents; Meta rejects
        // the message outright if one rides along with an audio.
        if ($media->caption && in_array($media->kind, ['image', 'video', 'document'], true)) {
            $node['caption'] = $media->caption;
        }

        // Without it the customer sees a random hash as the file name.
        if ($media->kind === 'document' && $media->filename) {
            $node['filename'] = $media->filename;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => $media->kind,
            $media->kind        => $node,
        ];

        if ($replyToProviderId) {
            $payload['context'] = ['message_id' => $replyToProviderId];
        }

        return new SendResult($this->post($payload));
    }

    /**
     * Send a map pin, rendered natively by WhatsApp.
     *
     * Name and address are optional for Meta but sent whenever we have them:
     * without them the customer gets bare coordinates and no way to act on them.
     */
    public function sendLocation(
        Conversation $conversation,
        OutboundLocation $location,
        ?string $replyToProviderId = null,
    ): SendResult {
        $node = [
            // Meta wants these as strings, not floats.
            'latitude'  => (string) $location->latitude,
            'longitude' => (string) $location->longitude,
        ];

        if ($location->name) {
            $node['name'] = $location->name;
        }

        if ($location->address) {
            $node['address'] = $location->address;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => 'location',
            'location'          => $node,
        ];

        if ($replyToProviderId) {
            $payload['context'] = ['message_id' => $replyToProviderId];
        }

        return new SendResult($this->post($payload));
    }

    /**
     * The media node Meta puts in the message, one level down under its type.
     *
     *     image:    { id, mime_type, sha256, caption? }
     *     document: { id, mime_type, filename, caption? }
     *     audio:    { id, mime_type, voice: true }
     */
    public function mediaReference(array $rawMessage): ?array
    {
        $type = $this->normalizeType((string) ($rawMessage['type'] ?? ''));

        if (! in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true)) {
            return null;
        }

        $node = $rawMessage[$rawMessage['type']] ?? null;

        if (! is_array($node) || empty($node['id'])) {
            return null;
        }

        return [
            'id'       => (string) $node['id'],
            'mime'     => $node['mime_type'] ?? null,
            'filename' => $node['filename'] ?? null,
        ];
    }

    /**
     * Two hops, both authenticated: the id resolves to a short-lived URL, and
     * that URL still needs the token — fetching it without one returns a 401
     * body that would otherwise be stored as if it were the file.
     */
    public function fetchMedia(string $mediaId): ?array
    {
        $version = (string) ($this->config['api_version'] ?? 'v21.0');
        $token = $this->required('token');

        $meta = Http::withToken($token)
            ->acceptJson()
            ->get(sprintf('https://graph.facebook.com/%s/%s', $version, $mediaId));

        if (! $meta->successful() || ! ($url = $meta->json('url'))) {
            return null;
        }

        $file = Http::withToken($token)->get($url);

        if (! $file->successful()) {
            return null;
        }

        return [
            'contents' => $file->body(),
            // Meta's own header wins over the webhook's mime_type: it is what
            // the bytes actually are.
            'mime'     => $file->header('Content-Type') ?: $meta->json('mime_type'),
        ];
    }

    /**
     * Send a tappable menu.
     *
     * Meta keeps buttons and lists in the same `interactive` envelope but with
     * different shapes underneath: buttons nest each option under `reply`, a
     * list groups rows into sections and needs the label of the button that
     * opens the sheet. One section is used deliberately — the parser produces a
     * flat menu, and an unnamed section renders cleaner than a fake heading.
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

        return new SendResult($this->post($payload));
    }

    /**
     * POST to the messages endpoint and pull out the wamid.
     *
     * Shared by every send: without a wamid there is no idempotency key and no
     * way to match the delivery receipt that arrives later, so a response
     * without one is a failure even though Meta answered 200.
     */
    protected function post(array $payload): string
    {
        $response = Http::withToken($this->required('token'))
            ->acceptJson()
            ->post($this->endpoint('messages'), $payload);

        $response->throw();

        $wamid = $response->json('messages.0.id');

        if (! $wamid) {
            throw new RuntimeException('Cloud API did not return a message id: '.$response->body());
        }

        return $wamid;
    }

    /**
     * Map Meta's type to ours ('contacts' is plural on the wire).
     */
    protected function normalizeType(string $type): string
    {
        return $type === 'contacts' ? 'contact' : $type;
    }

    /**
     * Human-readable body: text for text, caption for media, and — for a tap on
     * a button or a list row — the label the customer saw.
     *
     * Without this last case `body` stayed null and the AI agent threw the turn
     * away as an unsupported message, so a customer who answered by tapping got
     * no reply at all.
     */
    protected function extractBody(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'image', 'video', 'document', 'audio' => $message[$message['type']]['caption'] ?? null,
            'button'      => $message['button']['text'] ?? null,
            'interactive' => $this->interactiveSelection($message)['title'] ?? null,
            /*
             * El emoji es todo el contenido de una reacción.
             *
             * Cuando el cliente QUITA la reacción, Meta manda el mismo tipo con
             * `emoji` vacío. Se distingue con null: sin esto, quitar una
             * reacción y reaccionar con un emoji que no se pudo leer se veían
             * igual en el hilo.
             */
            'reaction'    => ($message['reaction']['emoji'] ?? '') !== ''
                ? $message['reaction']['emoji']
                : null,
            /*
             * El pin que el cliente comparte. Sin este caso `body` quedaba null
             * y el agente recibia el turno VACIO: type 'location' y nada mas,
             * asi que no podia guardar la direccion ni acusar recibo. Las
             * coordenadas estaban en `payload` pero nunca salian de la base.
             */
            'location'    => UbicacionDeMensaje::texto($message),
            default       => null,
        };
    }

    /**
     * The option a contact picked.
     *
     * Meta nests it one level down under the reply kind:
     *
     *     interactive: { type: "button_reply", button_reply: { id, title } }
     *     interactive: { type: "list_reply",   list_reply:   { id, title, description } }
     *
     * A quick-reply template button arrives as its own `button` type instead,
     * with `payload` where the others put an id.
     */
    public function interactiveSelection(array $rawMessage): ?array
    {
        if (isset($rawMessage['button'])) {
            return [
                'kind'  => 'button',
                'id'    => $rawMessage['button']['payload'] ?? null,
                'title' => $rawMessage['button']['text'] ?? null,
            ];
        }

        $interactive = $rawMessage['interactive'] ?? null;

        if (! is_array($interactive)) {
            return null;
        }

        $kind = $interactive['type'] ?? null;
        $reply = is_array($interactive[$kind] ?? null) ? $interactive[$kind] : null;

        if (! $reply) {
            return null;
        }

        return [
            'kind' => $kind,
            'id'   => $reply['id'] ?? null,
            // Meta always sends a title, but fall back to the id rather than
            // handing the agent a null it will discard.
            'title' => $reply['title'] ?? ($reply['id'] ?? null),
        ];
    }

    protected function endpoint(string $path): string
    {
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        return sprintf('https://graph.facebook.com/%s/%s/%s', $version, $this->required('phone_number_id'), $path);
    }
}
