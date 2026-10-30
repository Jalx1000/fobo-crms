<?php

namespace Webkul\Whatsapp\Gateways\Contracts;

use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Canales que pueden enviar WhatsApp Flows (formularios dentro del chat).
 * Solo la Cloud API de Meta; Kommo no los soporta.
 */
interface SupportsFlows
{
    /**
     * @param  array{token: string, name?: ?string, id?: ?string, cta: string}  $flow
     */
    public function sendFlow(Conversation $conversation, string $body, array $flow): SendResult;
}
