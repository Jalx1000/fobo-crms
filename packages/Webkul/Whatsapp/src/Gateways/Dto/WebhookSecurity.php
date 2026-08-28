<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * How a provider authenticates the webhook events it sends us. Declared per
 * driver so a weak posture can be surfaced instead of silently accepted.
 */
enum WebhookSecurity: string
{
    case SIGNATURE = 'signature';       // HMAC of the raw body (Cloud API)
    case HEADER_TOKEN = 'header_token'; // shared token in a header
    case URL_SECRET = 'url_secret';     // secret embedded in the URL (Kommo)
    case NONE = 'none';                 // nothing at all
}
