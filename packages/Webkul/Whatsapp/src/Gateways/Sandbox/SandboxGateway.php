<?php

namespace Webkul\Whatsapp\Gateways\Sandbox;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Webkul\Whatsapp\Gateways\BaseGateway;
use Webkul\Whatsapp\Gateways\Contracts\SupportsFlows;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Canal de los evals del agente: todo "se envía" y nada sale. El mensaje queda
 * como enviado con un id `sandbox-…`, así el resto del CRM (citas, notas,
 * historial) se comporta igual que con un paciente real.
 *
 * Sin ventana de 24h: los evals no reciben mensajes reales del paciente.
 */
class SandboxGateway extends BaseGateway implements SupportsFlows
{
    public function key(): string
    {
        return 'sandbox';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            send: ['text', 'template', 'flow', 'image', 'document', 'video'],
            receive: [],
        );
    }

    public function webhookSecurity(): WebhookSecurity
    {
        return WebhookSecurity::NONE;
    }

    public function authenticateWebhook(Request $request): WebhookAuth
    {
        return WebhookAuth::fail('El canal sandbox no recibe webhooks.');
    }

    public function parseWebhook(array $payload): InboundBatch
    {
        return new InboundBatch;
    }

    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult
    {
        return $this->sent();
    }

    public function sendTemplate(Conversation $conversation, string $templateKey, array $variables, ?string $language = null): SendResult
    {
        return $this->sent();
    }

    public function sendMedia(Conversation $conversation, string $type, string $link, ?string $caption = null, ?string $filename = null): SendResult
    {
        return $this->sent();
    }

    public function sendFlow(Conversation $conversation, string $body, array $flow): SendResult
    {
        return $this->sent();
    }

    protected function sent(): SendResult
    {
        return new SendResult('sandbox-'.Str::uuid());
    }
}
