<?php

namespace Webkul\Whatsapp\Gateways;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Resolves the messaging driver for a channel.
 *
 * One gateway is the *outbound default* for new conversations
 * ('whatsapp.gateway'), but every registered channel stays live: a conversation
 * always resolves through the driver that owns it, so WhatsApp and Messenger
 * threads coexist in the same inbox.
 *
 * Channels are declared in config('whatsapp.gateways'). A module that adds one
 * (Messenger) merges its entry in its own service provider's register(), the
 * same way a package merges any other config.
 */
class GatewayManager
{
    /**
     * Channels that address contacts by phone number and share the 24h window.
     * They are interchangeable for the same human — a contact migrated from
     * Kommo to Cloud API is one person, not two — which is what makes the
     * inbox's de-duplication safe. Anything outside a family is never merged.
     */
    public const DEFAULT_FAMILY = 'whatsapp';

    public function __construct(protected Container $app) {}

    /**
     * The gateway new outbound conversations are opened on.
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
        $config = $this->config($key);

        if (! is_array($config) || empty($config['driver'])) {
            throw new InvalidArgumentException(
                "Messaging channel [{$key}] is not configured. Check WHATSAPP_GATEWAY and config('whatsapp.gateways')."
            );
        }

        return $this->app->makeWith($config['driver'], ['config' => $config]);
    }

    /**
     * Resolve the driver that owns a conversation, so history created under a
     * previous provider — or on another channel — still resolves correctly.
     */
    public function for(Conversation $conversation): Gateway
    {
        return $this->driver($conversation->gateway ?: (string) config('whatsapp.gateway'));
    }

    /**
     * Whether a channel is installed and configured. Lets callers degrade
     * gracefully instead of blowing up on history from a removed module.
     */
    public function has(string $key): bool
    {
        $config = $this->config($key);

        return is_array($config) && ! empty($config['driver']);
    }

    /**
     * Every registered channel key.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys((array) config('whatsapp.gateways', []));
    }

    /**
     * Human label for a channel, for the inbox badge and the profile drawer.
     * Never let a raw key like "cloud_api" reach the screen.
     */
    public function label(string $key): string
    {
        return (string) ($this->config($key)['label'] ?? Str::headline($key));
    }

    /**
     * The family a channel belongs to. Channels in the same family address the
     * same identity (a phone number), so the inbox may collapse duplicates
     * across them; channels in different families never merge.
     */
    public function family(string $key): string
    {
        return (string) ($this->config($key)['family'] ?? $key);
    }

    /**
     * Keys sharing a family.
     *
     * @return array<int, string>
     */
    public function keysInFamily(string $family): array
    {
        return array_values(array_filter(
            $this->keys(),
            fn (string $key) => $this->family($key) === $family,
        ));
    }

    /**
     * Channel descriptors for the front-end: what the inbox filter lists and
     * what each row's badge renders from.
     *
     * @return array<int, array{key: string, label: string, family: string}>
     */
    public function channels(): array
    {
        return array_map(fn (string $key) => [
            'key'    => $key,
            'label'  => $this->label($key),
            'family' => $this->family($key),
        ], $this->keys());
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function config(string $key): ?array
    {
        $config = config("whatsapp.gateways.{$key}");

        return is_array($config) ? $config : null;
    }
}
