<?php

namespace Webkul\Whatsapp\Gateways;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Resolves the messaging driver. One gateway is active per installation
 * ('whatsapp.gateway'); each driver receives its own config array.
 */
class GatewayManager
{
    public function __construct(protected Container $app) {}

    /**
     * The gateway configured for this installation.
     */
    public function active(): Gateway
    {
        return $this->driver((string) config('whatsapp.gateway'));
    }

    /**
     * Resolve a specific driver by key.
     */
    public function driver(string $key): Gateway
    {
        $config = config("whatsapp.gateways.{$key}");

        if (! is_array($config) || empty($config['driver'])) {
            throw new InvalidArgumentException(
                "WhatsApp gateway [{$key}] is not configured. Check WHATSAPP_GATEWAY and config('whatsapp.gateways')."
            );
        }

        return $this->app->makeWith($config['driver'], ['config' => $config]);
    }

    /**
     * Resolve the driver that owns a conversation, so history created under a
     * previous provider still resolves correctly after a switch.
     */
    public function for(Conversation $conversation): Gateway
    {
        return $this->driver($conversation->gateway ?: (string) config('whatsapp.gateway'));
    }
}
