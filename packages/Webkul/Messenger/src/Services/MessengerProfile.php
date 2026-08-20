<?php

namespace Webkul\Messenger\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Messenger\Models\SocialConnection;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * The public profile behind a PSID.
 *
 * Meta sends no name in the webhook, so putting a human name on a Messenger
 * thread costs a Graph call. It is made once per contact: after the first
 * message the name already sits on the conversation, and that is what we read.
 */
class MessengerProfile
{
    public function __construct(protected MediaLibrary $media) {}

    /**
     * @return array{name: ?string, avatar_url: ?string}
     */
    public function fetch(SocialConnection $connection, string $psid): array
    {
        if ($known = $this->known($psid)) {
            return $known;
        }

        if (! $connection->access_token) {
            return ['name' => null, 'avatar_url' => null];
        }

        try {
            $response = Http::timeout(10)
                ->withToken($connection->access_token)
                ->acceptJson()
                ->get($this->endpoint($psid), ['fields' => 'first_name,last_name,profile_pic']);

            if (! $response->successful()) {
                // El status solo no alcanza para diagnosticar: un 400 puede ser
                // el token de otra pagina, un permiso sin aprobar o un campo que
                // la app no tiene habilitado, y Graph los distingue por
                // error.code / error.error_subcode en el cuerpo.
                Log::warning('Messenger: profile lookup unavailable.', [
                    'connection_id' => $connection->id,
                    'page_id'       => $connection->account_id,
                    'psid'          => $psid,
                    'status'        => $response->status(),
                    'graph_error'   => $response->json('error'),
                ]);

                return ['name' => null, 'avatar_url' => null];
            }

            $name = trim(trim((string) $response->json('first_name')).' '.trim((string) $response->json('last_name')));
            $picture = $response->json('profile_pic');

            return [
                'name'       => $name ?: null,
                // Meta's picture URLs expire, so the bytes are copied locally
                // and served from the CRM. A thread must not go blank because a
                // CDN signature timed out.
                'avatar_url' => is_string($picture) && $picture !== ''
                    ? $this->media->mirror($picture)
                    : null,
            ];
        } catch (Throwable $e) {
            Log::warning('Messenger: profile lookup failed.', [
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);

            return ['name' => null, 'avatar_url' => null];
        }
    }

    /**
     * What we already know about this PSID, from the conversation itself.
     *
     * @return array{name: ?string, avatar_url: ?string}|null
     */
    protected function known(string $psid): ?array
    {
        $conversation = ConversationProxy::modelClass()::query()
            ->where('gateway', 'messenger')
            ->where('provider_conversation_id', $psid)
            ->first(['wa_name', 'avatar_url']);

        if (! $conversation || ! $conversation->wa_name) {
            return null;
        }

        return [
            'name'       => $conversation->wa_name,
            'avatar_url' => $conversation->avatar_url,
        ];
    }

    protected function endpoint(string $psid): string
    {
        $version = trim((string) config('messenger.graph_version'), '/');

        return "https://graph.facebook.com/{$version}/{$psid}";
    }
}
