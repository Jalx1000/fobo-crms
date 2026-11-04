<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Whatsapp\Exceptions\UnsupportedMessageException;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Gateways\Kommo\KommoGateway;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Sending a quote as an approved template (HSM) - the only way to reach a
 * contact once the 24h service window has closed.
 *
 * Graph is faked everywhere: no test in this file reaches Meta.
 *
 * Helper names are prefixed `tpl` on purpose: Pest loads every test file into
 * one process, and WhatsappOutboundMediaTest already owns cloudGateway() and
 * friends.
 */
function tplGateway(): CloudApiGateway
{
    return new CloudApiGateway([
        'token'           => 'test-token',
        'phone_number_id' => '1234567890',
        'api_version'     => 'v21.0',
    ]);
}

function tplConversation(): Conversation
{
    return (new Conversation)->forceFill(['id' => 7, 'wa_phone' => '59175640698']);
}

function tplPdf(string $name = 'Cotizacion-12.pdf'): OutboundMedia
{
    Storage::fake('public');
    Storage::disk('public')->put('whatsapp/media/7/'.$name, '%PDF-1.4 fake');

    return new OutboundMedia(
        type: 'document',
        path: 'whatsapp/media/7/'.$name,
        mime: 'application/pdf',
        filename: $name,
        caption: null,
    );
}

function tplFakeGraph(): void
{
    Http::fake([
        '*/1234567890/media'    => Http::response(['id' => 'MEDIA-777'], 200),
        '*/1234567890/messages' => Http::response(['messages' => [['id' => 'wamid.TPL']]], 200),
    ]);
}

/** The JSON body of the /messages call. */
function tplSentPayload(): array
{
    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), '/messages')) {
            return $request->data();
        }
    }

    return [];
}

it('uploads the PDF first and references the returned media id in the header', function () {
    tplFakeGraph();

    $result = tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
        name: 'cotizacion_documento',
        language: 'es',
        bodyParams: ['Juan', 'Banners', 'Bs 1.200', '30/09/2026'],
        headerMedia: tplPdf(),
    ));

    expect($result->providerMessageId)->toBe('wamid.TPL');

    // Two calls, in order: upload, then send. The id from the first has to be
    // the one referenced by the second.
    $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
    expect($urls[0])->toContain('/media')
        ->and($urls[1])->toContain('/messages');

    $header = tplSentPayload()['template']['components'][0];

    expect($header['type'])->toBe('header')
        ->and($header['parameters'][0]['type'])->toBe('document')
        ->and($header['parameters'][0]['document']['id'])->toBe('MEDIA-777')
        // Without the filename the handset shows the opaque media id.
        ->and($header['parameters'][0]['document']['filename'])->toBe('Cotizacion-12.pdf');
});

it('posts a template message with the approved name and language', function () {
    tplFakeGraph();

    tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
        name: 'cotizacion_documento',
        language: 'es',
        headerMedia: tplPdf(),
    ));

    $payload = tplSentPayload();

    expect($payload['type'])->toBe('template')
        ->and($payload['to'])->toBe('59175640698')
        ->and($payload['template']['name'])->toBe('cotizacion_documento')
        // Meta wants an object here, not a bare string.
        ->and($payload['template']['language'])->toBe(['code' => 'es']);
});

it('sends the body parameters in positional order', function () {
    tplFakeGraph();

    tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
        name: 'cotizacion_documento',
        language: 'es',
        bodyParams: ['Juan', 'Banners', 'Bs 1.200', '30/09/2026'],
        headerMedia: tplPdf(),
    ));

    $body = collect(tplSentPayload()['template']['components'])
        ->firstWhere('type', 'body');

    // {{1}} is the first element. A reordered array would still be accepted by
    // Meta and would silently put the total where the name belongs.
    expect(array_column($body['parameters'], 'text'))
        ->toBe(['Juan', 'Banners', 'Bs 1.200', '30/09/2026'])
        ->and(array_unique(array_column($body['parameters'], 'type')))
        ->toBe(['text']);
});

it('refuses a template with no name or language instead of calling Graph', function () {
    tplFakeGraph();

    expect(fn () => tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
        name: '',
        language: 'es',
        headerMedia: tplPdf(),
    )))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('sends a template with no header when there is no file', function () {
    tplFakeGraph();

    tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
        name: 'cotizacion_documento',
        language: 'es',
        bodyParams: ['Juan'],
    ));

    $types = array_column(tplSentPayload()['template']['components'], 'type');

    expect($types)->toBe(['body']);
});

it('refuses to send a template over a channel that has none', function () {
    // Kommo proxies someone else's inbox: it cannot address Meta's template
    // catalogue. It must refuse loudly rather than silently drop the message.
    $kommo = new KommoGateway(['subdomain' => 'test', 'token' => 'x']);

    expect(fn () => $kommo->sendTemplate(tplConversation(), new OutboundTemplate(
        name: 'cotizacion_documento',
        language: 'es',
    )))->toThrow(UnsupportedMessageException::class);
});

/*
|--------------------------------------------------------------------------
| Slice 2 - which route the pipeline picks
|--------------------------------------------------------------------------
| These exercise the decision and the routing, not persistence: every model
| here is built with forceFill and never saved, so the tests run without a
| database. The row-level assertions live in the Slice 3 tests.
*/

/** A driver that records what it was asked to send instead of talking to Meta. */
function tplSpyGateway(): object
{
    return new class(['token' => 'x']) extends Webkul\Whatsapp\Gateways\BaseGateway
    {
        public array $calls = [];

        public function key(): string
        {
            return 'cloud_api';
        }

        public function capabilities(): Webkul\Whatsapp\Gateways\Dto\Capabilities
        {
            return new Webkul\Whatsapp\Gateways\Dto\Capabilities(send: ['text', 'document']);
        }

        public function webhookSecurity(): Webkul\Whatsapp\Gateways\Dto\WebhookSecurity
        {
            return Webkul\Whatsapp\Gateways\Dto\WebhookSecurity::NONE;
        }

        public function messagingWindowHours(): ?int
        {
            return 24;
        }

        public function authenticateWebhook(Illuminate\Http\Request $request): Webkul\Whatsapp\Gateways\Dto\WebhookAuth
        {
            return new Webkul\Whatsapp\Gateways\Dto\WebhookAuth(true);
        }

        public function parseWebhook(array $payload): Webkul\Whatsapp\Gateways\Dto\InboundBatch
        {
            return new Webkul\Whatsapp\Gateways\Dto\InboundBatch([], []);
        }

        public function sendText($conversation, string $body, ?string $replyTo = null): Webkul\Whatsapp\Gateways\Dto\SendResult
        {
            $this->calls[] = ['text', $body];

            return new Webkul\Whatsapp\Gateways\Dto\SendResult('wamid.TEXT');
        }

        public function sendMedia($conversation, Webkul\Whatsapp\Gateways\Dto\OutboundMedia $media, ?string $replyTo = null): Webkul\Whatsapp\Gateways\Dto\SendResult
        {
            $this->calls[] = ['media', $media];

            return new Webkul\Whatsapp\Gateways\Dto\SendResult('wamid.MEDIA');
        }

        public function sendTemplate($conversation, OutboundTemplate $template, ?string $replyTo = null): Webkul\Whatsapp\Gateways\Dto\SendResult
        {
            $this->calls[] = ['template', $template];

            return new Webkul\Whatsapp\Gateways\Dto\SendResult('wamid.TPL');
        }
    };
}

/** Run SendMessage's routing against a spy driver, without queue or database. */
function tplDeliver(array $payload): array
{
    $spy = tplSpyGateway();

    $manager = new class(app(), $spy) extends Webkul\Whatsapp\Gateways\GatewayManager
    {
        public function __construct(Illuminate\Contracts\Container\Container $app, protected object $spy)
        {
            parent::__construct($app);
        }

        public function for(Webkul\Whatsapp\Models\Conversation $conversation): Webkul\Whatsapp\Gateways\Contracts\Gateway
        {
            return $this->spy;
        }
    };

    $message = (new Webkul\Whatsapp\Models\Message)->forceFill([
        'id'         => 99,
        'type'       => 'document',
        'body'       => 'caption',
        'media_mime' => 'application/pdf',
        'media_name' => 'Cotizacion-12.pdf',
        'payload'    => $payload,
    ]);
    $message->setRelation('conversation', tplConversation());
    $message->setRelation('replyTo', null);

    $deliver = new ReflectionMethod(Webkul\Whatsapp\Jobs\SendMessage::class, 'deliver');
    $deliver->setAccessible(true);
    $deliver->invoke(new Webkul\Whatsapp\Jobs\SendMessage(99), $manager, $message);

    return $spy->calls[0];
}

function tplConversationWithInbound(?string $lastInboundAt): Webkul\Whatsapp\Models\Conversation
{
    return (new Webkul\Whatsapp\Models\Conversation)->forceFill([
        'id'              => 7,
        'gateway'         => 'cloud_api',
        'wa_phone'        => '59175640698',
        'last_inbound_at' => $lastInboundAt,
    ]);
}

it('routes a row carrying a template descriptor to sendTemplate', function () {
    [$kind, $template] = tplDeliver([
        'media_disk_path' => 'whatsapp/media/7/x.pdf',
        'template'        => ['name' => 'cotizacion_documento', 'language' => 'es', 'body_params' => ['Juan']],
    ]);

    expect($kind)->toBe('template')
        ->and($template->name)->toBe('cotizacion_documento')
        ->and($template->bodyParams)->toBe(['Juan'])
        // The PDF has to survive the route change: it becomes the header.
        ->and($template->headerMedia?->filename)->toBe('Cotizacion-12.pdf');
});

it('routes a row without a template descriptor to sendMedia, as before', function () {
    // Guards the ordering bug: isMedia('document') is true, so if the template
    // branch were checked second it would never run - and if it were checked
    // too loosely, this plain document would wrongly go out as a template.
    [$kind, $media] = tplDeliver(['media_disk_path' => 'whatsapp/media/7/x.pdf']);

    expect($kind)->toBe('media')
        ->and($media->filename)->toBe('Cotizacion-12.pdf');
});

it('reports a media row that lost its file instead of failing with a TypeError', function () {
    expect(fn () => tplDeliver([]))->toThrow(RuntimeException::class, 'no tiene ningún archivo guardado');
});

it('needs a template only once the 24h window has closed', function () {
    $dispatcher = app(Webkul\Whatsapp\Services\DocumentDispatcher::class);

    expect($dispatcher->requiresTemplate(tplConversationWithInbound(now()->subHours(2))))->toBeFalse()
        ->and($dispatcher->requiresTemplate(tplConversationWithInbound(now()->subHours(30))))->toBeTrue()
        // Never wrote at all: the window was never open.
        ->and($dispatcher->requiresTemplate(tplConversationWithInbound(null)))->toBeTrue();
});

it('refuses to queue a free-form document once the window has closed', function () {
    // Without this guard the row would be written, sit in the queue, and come
    // back minutes later as a failed 131047 nobody is watching for.
    $dispatcher = app(Webkul\Whatsapp\Services\DocumentDispatcher::class);

    expect(fn () => $dispatcher->queue(
        tplConversationWithInbound(now()->subHours(30)),
        '%PDF-1.4 fake',
        'Cotizacion-12.pdf',
        'application/pdf',
    ))->toThrow(Webkul\Whatsapp\Exceptions\MessagingWindowClosedException::class);
});

/*
|--------------------------------------------------------------------------
| Slice 3 - the parameters the quote action feeds the template
|--------------------------------------------------------------------------
*/

/** Build the template the controller would send, without touching the database. */
function tplForQuote(array $quoteAttributes, ?string $personName = 'Juan Pérez'): ?OutboundTemplate
{
    $quote = (new Webkul\Quote\Models\Quote)->forceFill($quoteAttributes);
    $person = (new Webkul\Contact\Models\Person)->forceFill(['id' => 3, 'name' => $personName]);

    $method = new ReflectionMethod(Webkul\Admin\Http\Controllers\Quote\QuoteController::class, 'quoteTemplate');
    $method->setAccessible(true);

    return $method->invoke(app(Webkul\Admin\Http\Controllers\Quote\QuoteController::class), $quote, $person);
}

it('builds exactly the two body parameters the approved template declares', function () {
    $template = tplForQuote([
        'id'          => 12,
        'subject'     => 'Farmacorp',
        'grand_total' => 1200,
    ]);

    expect($template->name)->toBe(config('whatsapp.templates.quote.name'))
        ->and($template->language)->toBe(config('whatsapp.templates.quote.language'))
        // Meta counts these and rejects the whole message with 132000 over a
        // mismatch, without ever saying how many it wanted.
        ->and($template->bodyParams)->toHaveCount(2)
        ->and($template->bodyParams[0])->toBe('Farmacorp')
        ->and($template->bodyParams[1])->toContain('1');
});

it('never lets a body parameter resolve to an empty string', function () {
    // Meta rejects the WHOLE message with 132000 over a single blank parameter,
    // so every variable needs a fallback: a quote with no subject and no total
    // still has to produce two usable values.
    $method = new ReflectionMethod(Webkul\Admin\Http\Controllers\Quote\QuoteController::class, 'templateBodyParams');
    $method->setAccessible(true);

    $params = $method->invoke(
        app(Webkul\Admin\Http\Controllers\Quote\QuoteController::class),
        null,   // cotización sin asunto
        null,   // sin total
        12,
    );

    expect($params)->toHaveCount(2);

    foreach ($params as $index => $value) {
        expect(trim((string) $value))->not->toBe('', 'el parámetro {{'.($index + 1).'}} salió vacío');
    }

    expect($params[0])->toBe('#12');
});

it('reports a missing template configuration instead of sending a nameless one', function () {
    config()->set('whatsapp.templates.quote.name', '');

    expect(tplForQuote(['id' => 12, 'subject' => 'Banners', 'grand_total' => 10]))->toBeNull();
});

it('explains a parameter-count rejection instead of dumping Graph JSON', function () {
    Http::fake([
        '*/1234567890/media'    => Http::response(['id' => 'MEDIA-1'], 200),
        '*/1234567890/messages' => Http::response([
            'error' => ['message' => '(#132000) Number of parameters does not match', 'code' => 132000],
        ], 400),
    ]);

    // Graph's own text is a truncated JSON blob; the salesperson needs to know
    // WHICH template and HOW MANY variables were sent, because Meta never says
    // how many it expected.
    expect(fn () => tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
        name: 'cotizacion_documento_v2',
        language: 'es',
        bodyParams: ['a', 'b', 'c', 'd'],
        headerMedia: tplPdf(),
    )))->toThrow(RuntimeException::class, 'cotizacion_documento_v2');

    try {
        tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
            name: 'cotizacion_documento_v2',
            language: 'es',
            bodyParams: ['a', 'b', 'c', 'd'],
            headerMedia: tplPdf(),
        ));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('(4)')
            ->toContain('WhatsApp Manager');
    }
});

it('names the template and language when neither is approved', function () {
    Http::fake([
        '*/1234567890/media'    => Http::response(['id' => 'MEDIA-1'], 200),
        '*/1234567890/messages' => Http::response([
            'error' => ['message' => '(#132001) Template not found', 'code' => 132001],
        ], 400),
    ]);

    // Name and language are a pair; either being wrong produces this same code.
    expect(fn () => tplGateway()->sendTemplate(tplConversation(), new OutboundTemplate(
        name: 'cotizacion_documento_v2',
        language: 'es_MX',
        headerMedia: tplPdf(),
    )))->toThrow(RuntimeException::class, 'es_MX');
});
