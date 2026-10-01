<?php

/*
|--------------------------------------------------------------------------
| Facebook Messenger channel
|--------------------------------------------------------------------------
| Credentials for the Messenger driver. This array is merged into
| config('whatsapp.gateways.messenger') by MessengerServiceProvider, so the
| inbox resolves Messenger exactly like any other channel — there is no second
| pipeline, no second store and no second inbox.
|
| Per-page credentials (page id + page access token) do NOT live here: a tenant
| can connect several pages, so they are rows in `social_connections`, created
| with `php artisan messenger:connect-page`.
*/

return [
    'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),

    // Echoed back to Meta during the webhook registration handshake.
    'verify_token' => env('MESSENGER_VERIFY_TOKEN'),

    // App secret used to verify the HMAC on every delivery. Falls back to the
    // shared Meta app secret when the same app serves WhatsApp and Messenger.
    'app_secret' => env('MESSENGER_APP_SECRET')
        ?: env('META_APP_SECRET')
        ?: env('FACEBOOK_APP_SECRET'),

    // Absolute base for the media URLs handed to the front-end (avatars are
    // proxied through the CRM instead of hot-linking Meta's expiring CDN URLs).
    'backend_domain' => env('BACKEND_DOMAIN') ?: env('APP_URL'),

    // Look up the sender's public profile (name + picture) the first time they
    // write. Off = threads show the raw PSID until a human names the contact.
    'fetch_profile' => env('MESSENGER_FETCH_PROFILE', true),

    // Inbound attachments arrive as expiring CDN URLs; the ingest job copies
    // them to local storage. Cap what we are willing to pull, in kilobytes.
    'max_media_kb' => env('MESSENGER_MAX_MEDIA_KB', 25600), // 25 MB
];
