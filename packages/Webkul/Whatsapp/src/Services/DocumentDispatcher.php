<?php

namespace Webkul\Whatsapp\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Exceptions\MessagingWindowClosedException;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Sends a document the CRM generated itself (a quote PDF today, an invoice or a
 * report tomorrow) into a contact's WhatsApp thread.
 *
 * Deliberately thin: it reuses the same pipeline the inbox composer uses — stage
 * the file on the public disk, write an outbound `whatsapp_messages` row with
 * status `queued`, let the SendMessage job talk to the provider. That way the
 * document shows up in the thread and in the profile drawer's "Docs" tab exactly
 * like one an advisor attached by hand, and it inherits retries, status webhooks
 * and error reporting for free.
 */
class DocumentDispatcher
{
    public function __construct(
        protected GatewayManager $gateways,
        protected ConversationResolver $resolver,
        protected OutboundAttachment $attachments,
    ) {}

    /**
     * The thread this document belongs in.
     *
     * A contact already talking to us must not get a second thread, so an
     * existing conversation always wins over the phone number — and only
     * phone-family channels are considered: "enviar por WhatsApp" must never
     * silently deliver over Messenger just because that thread is more recent.
     *
     * Falls back to opening a conversation for the number when there is none.
     *
     * @param  int|null  $personId  The CRM contact the document is about.
     * @param  string|null  $phone  Used only when the contact has no thread yet.
     */
    public function conversationFor(?int $personId, ?string $phone): ?Model
    {
        $model = ConversationProxy::modelClass();

        if ($personId) {
            $existing = $model::where('person_id', $personId)
                ->whereIn('gateway', $this->gateways->keysInFamily(GatewayManager::DEFAULT_FAMILY))
                // Same tie-break as the inbox: the active gateway first, because
                // it is the only one we can actually send through; recency alone
                // would surface a thread from a decommissioned provider.
                ->orderByRaw('gateway = ? desc', [(string) config('whatsapp.gateway')])
                ->orderByDesc('last_message_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return $phone ? $this->resolver->findOrCreate($phone) : null;
    }

    /**
     * The 24h service window for a thread.
     *
     * Outside it WhatsApp only accepts approved templates, and this pipeline
     * sends free-form media — so the caller has to check before queueing rather
     * than discover it as a failed row minutes later.
     */
    public function window(Model $conversation): MessagingWindow
    {
        return MessagingWindow::compute(
            $this->gateways->for($conversation)->messagingWindowHours(),
            $conversation->last_inbound_at,
        );
    }

    /**
     * Whether this thread can only be reached through an approved template.
     *
     * Exposed so a caller can tell the user which of the two routes is about to
     * happen — sending a template costs money and uses fixed text, and that
     * should never be silent.
     */
    public function requiresTemplate(Model $conversation): bool
    {
        $window = $this->window($conversation);

        return $window->applies && ! $window->open;
    }

    /**
     * How many messages went out as an approved template since a given moment.
     *
     * Lives next to queue() on purpose: this class is what decides that a send
     * is a template and what writes `payload['template']`, so it is also the
     * honest place to read that back. Anywhere else would be guessing at the
     * shape of someone else's payload.
     *
     * Counts every template send, not only quotes — that is exactly the number
     * an administrator watching the bill wants, and it stays correct if another
     * feature starts sending templates too.
     */
    public function templatesSentSince(CarbonInterface $since): int
    {
        return MessageProxy::modelClass()::query()
            ->where('direction', 'outbound')
            ->whereNotNull('payload->template')
            // A queued row that later failed still cost nothing, and a row that
            // never left is not a send. Only what actually reached Meta counts.
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Queue the document. Returns the message row so the caller can report it.
     *
     * The row is ALWAYS `type = 'document'`, whichever route it takes: `type` is
     * the contract with the inbox front-end (and with the Docs tab, which
     * filters on it), while how it reaches the handset is transport. The
     * template descriptor therefore rides in `payload`, next to media_disk_path,
     * and only SendMessage reads it.
     *
     * @param  string  $contents  The raw bytes (an already rendered PDF).
     * @param  string|null  $caption  Rides along with the file; WhatsApp accepts
     *                                a caption on documents. Ignored on the
     *                                template route — the approved body wins.
     * @param  OutboundTemplate|null  $template  Used ONLY when the 24h window is
     *                                           closed. Inside the window we
     *                                           send free-form: the text stays
     *                                           editable and the send does not
     *                                           depend on Meta keeping the
     *                                           template approved.
     *
     * @throws MessagingWindowClosedException When the window is shut and no
     *                                        template was supplied. Refusing
     *                                        here is what makes it impossible to
     *                                        queue a message that can only fail.
     */
    public function queue(
        Model $conversation,
        string $contents,
        string $filename,
        string $mime,
        ?string $caption = null,
        ?OutboundTemplate $template = null,
    ): Model {
        $useTemplate = $this->requiresTemplate($conversation);

        if ($useTemplate && ! $template) {
            throw MessagingWindowClosedException::noTemplate($conversation->id);
        }

        $message = DB::transaction(function () use ($conversation, $contents, $filename, $mime, $caption, $template, $useTemplate) {
            $file = $this->attachments->stageContents($contents, $filename, $mime, $conversation->id);

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'document',
                'body'            => $caption,
                'media_path'      => $file['url'],
                'media_mime'      => $file['mime'],
                'media_name'      => $file['name'],
                'status'          => 'queued',
                // Not 'agent': a human pressed the button. The thread and the
                // handoff rules both key off this.
                'sender'          => 'human',
                // Disk-relative path for the gateway; media_path is the public
                // URL the browser renders. `template` is present only on the
                // out-of-window route and is what SendMessage routes on.
                'payload'         => array_filter([
                    'media_disk_path' => $file['path'],
                    'template'        => $useTemplate ? $template->toArray() : null,
                ]),
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return $message;
    }
}
