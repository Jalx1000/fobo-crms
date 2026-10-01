<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\PipelineProxy;
use Webkul\Lead\Models\SourceProxy;
use Webkul\Lead\Models\TypeProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\ConversationProxy;

class ConversationResolver
{
    /**
     * Resolve the conversation for an inbound message.
     *
     * Identity is channel-dependent: Cloud API gives a phone, Kommo gives its
     * lead id (and the phone only after a lookup, which may fail), Messenger
     * gives a page-scoped id and never a phone. Match on whichever is
     * available, preferring the provider's own id since it is exact.
     *
     * @param  string|null  $providerAccountId  Which of our accounts received it
     *                                          (a Messenger page), so the reply
     *                                          goes back out the same way.
     */
    public function resolve(
        ContactIdentity $contact,
        ?string $providerConversationId,
        string $gateway,
        ?string $providerAccountId = null,
    ): ?Model {
        $model = ConversationProxy::modelClass();

        if ($providerConversationId) {
            // Key STRICTLY by the provider's id. Falling back to the phone here
            // would merge a second Kommo lead into the first lead's thread, and
            // replies would then be addressed to the wrong lead.
            $conversation = $model::where('gateway', $gateway)
                ->where('provider_conversation_id', $providerConversationId)
                ->first();
        } elseif ($contact->phone) {
            $conversation = $this->findConversationByPhone($contact->phone, $gateway);
        } else {
            return null; // nothing to key on
        }

        if ($conversation) {
            return $this->backfill($conversation, $contact, $providerConversationId, $providerAccountId);
        }

        $person = $this->resolveOrCreatePerson($contact, $gateway);

        return $model::create([
            'gateway'                  => $gateway,
            // Only phone channels have one. Writing a channel id here would
            // make a PSID look like a number to every phone-based lookup.
            'wa_phone'                 => PhoneNumber::normalize($contact->phone) ?: null,
            'provider_conversation_id' => $providerConversationId,
            'provider_account_id'      => $providerAccountId,
            'wa_name'                  => $contact->name,
            'avatar_url'               => $contact->avatarUrl,
            'person_id'                => $person?->id,
            'lead_id'                  => $this->resolveOrCreateLeadId($person, $gateway),
            'status'                   => $person ? 'open' : 'unassigned',
            // Whether the AI agent takes new conversations on this channel.
            // Declared per channel: an agent written and tested for WhatsApp
            // must not start answering a channel nobody trained it on just
            // because that channel was plugged in.
            'ai_enabled'               => (bool) config("whatsapp.gateways.{$gateway}.ai_default", true),
        ]);
    }

    /**
     * Fill in details we learn later (the provider id on a phone-matched
     * conversation, a name, or a Person once the number gets registered).
     */
    protected function backfill(
        Model $conversation,
        ContactIdentity $contact,
        ?string $providerConversationId,
        ?string $providerAccountId = null,
    ): Model {
        $updates = [];

        if ($providerConversationId && ! $conversation->provider_conversation_id) {
            $updates['provider_conversation_id'] = $providerConversationId;
        }

        if ($providerAccountId && ! $conversation->provider_account_id) {
            $updates['provider_account_id'] = $providerAccountId;
        }

        if ($contact->name && ! $conversation->wa_name) {
            $updates['wa_name'] = $contact->name;
        }

        if ($contact->avatarUrl && ! $conversation->avatar_url) {
            $updates['avatar_url'] = $contact->avatarUrl;
        }

        if ($contact->phone && ! $conversation->wa_phone) {
            $updates['wa_phone'] = PhoneNumber::normalize($contact->phone);
        }

        // A conversation may have been created before the phone was known (Kommo
        // does not send it), so it can still be waiting for its Person.
        if (! $conversation->person_id) {
            if ($person = $this->resolveOrCreatePerson($contact, $conversation->gateway)) {
                $updates['person_id'] = $person->id;
                $updates['lead_id'] = $this->resolveOrCreateLeadId($person, $conversation->gateway);
                $updates['status'] = 'open';
            }
        }

        // Backfill a lead for a conversation that has a Person but never got one
        // (created before auto-lead existed, or the contact had no lead yet).
        if (! $conversation->lead_id && ! isset($updates['lead_id']) && $conversation->person_id) {
            $person = PersonProxy::modelClass()::find($conversation->person_id);

            if ($leadId = $this->resolveOrCreateLeadId($person, $conversation->gateway)) {
                $updates['lead_id'] = $leadId;
            }
        }

        if ($updates) {
            $conversation->update($updates);
        }

        return $conversation;
    }

    /**
     * Find or create the conversation for an outbound message addressed by phone.
     */
    public function findOrCreate(string $phone, ?string $name = null): Model
    {
        return $this->resolve(
            new ContactIdentity(phone: $phone, name: $name),
            null,
            (string) config('whatsapp.gateway'),
        );
    }

    /**
     * Find a conversation for a phone, tolerant of whether the country code is
     * present. A number stored without one ("+75640698") must still match the
     * international form the provider sends ("+59175640698") — otherwise every
     * inbound would spawn a duplicate conversation for the same contact.
     *
     * Exact (indexed) match first; then a trailing-digits match, the same fuzzy
     * scheme resolvePerson() uses for Person.contact_numbers.
     */
    protected function findConversationByPhone(string $phone, string $gateway): ?Model
    {
        $model = ConversationProxy::modelClass();

        $exact = $model::where('gateway', $gateway)
            ->where('wa_phone', PhoneNumber::normalize($phone))
            ->first();

        if ($exact) {
            return $exact;
        }

        $tail = PhoneNumber::tail($phone);

        if (strlen($tail) < 7) {
            return null;
        }

        return $model::where('gateway', $gateway)
            ->where('wa_phone', 'like', '%'.$tail)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The lead a conversation points at: the contact's most recent one, or a
     * freshly opened lead when they have none. Off (config) leaves it null for a
     * human to create from the profile drawer.
     */
    protected function resolveOrCreateLeadId(?Model $person, string $gateway): ?int
    {
        if (! $person) {
            return null;
        }

        if ($existing = $person->leads()->latest()->first()) {
            return $existing->id;
        }

        if (! config('whatsapp.auto_create_lead', true)) {
            return null;
        }

        return optional($this->createLead($person, $gateway))->id;
    }

    /**
     * Open a lead for a messaging contact — default pipeline + first stage, and
     * the channel's own source so the pipeline reports where the demand came
     * from. Mirrors the manual quick-create in ChatController so both paths land
     * identical leads. Never lets a failure lose the message.
     */
    protected function createLead(Model $person, string $gateway): ?Model
    {
        try {
            $pipeline = PipelineProxy::modelClass()::where('is_default', 1)->first()
                ?? PipelineProxy::modelClass()::first();

            if (! $pipeline) {
                return null;
            }

            $stage = $pipeline->stages()->orderBy('sort_order')->first();
            $source = SourceProxy::modelClass()::firstOrCreate(['name' => $this->leadSource($gateway)]);
            $type = TypeProxy::modelClass()::first();

            Event::dispatch('lead.create.before');

            $lead = app(LeadRepository::class)->create([
                'entity_type'            => 'leads',
                'title'                  => $this->leadSource($gateway).' - '.($person->name ?: 'Contacto'),
                'lead_value'             => 0,
                'person'                 => ['id' => $person->id],
                'lead_pipeline_id'       => $pipeline->id,
                'lead_pipeline_stage_id' => $stage?->id,
                'lead_source_id'         => $source->id,
                'lead_type_id'           => optional($type)->id,
                'status'                 => 1,
            ]);

            Event::dispatch('lead.create.after', $lead);

            Log::info('WhatsApp: auto-created lead for a conversation', [
                'lead_id'   => $lead->id,
                'person_id' => $person->id,
            ]);

            return $lead;
        } catch (Throwable $e) {
            Log::warning('WhatsApp: could not auto-create lead', [
                'person_id' => $person->id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The Person behind an incoming message: the one already in the CRM, or a
     * newly registered one.
     *
     * Only a phone number identifies a human across channels. A page-scoped id
     * (Messenger) is meaningless anywhere else, so a channel without a phone
     * never *matches* an existing contact — it registers a new one and leaves
     * merging to a human, which is the only safe direction to be wrong in:
     * a duplicate contact can be merged, a wrongly merged one cannot be undone.
     */
    public function resolveOrCreatePerson(ContactIdentity $contact, string $gateway): ?Model
    {
        if ($contact->phone && $person = $this->resolvePerson($contact->phone)) {
            return $person;
        }

        if ($contact->isEmpty() || ! config('whatsapp.auto_create_person')) {
            return null;
        }

        // On a phone channel a missing number means "not known YET" — Kommo
        // delivers it in a second call that can fail or lag. Registering a
        // phone-less Person now would produce a duplicate the moment the number
        // arrives and matches a real contact, so we wait: backfill() links the
        // conversation as soon as the phone shows up.
        if (! $contact->phone && $this->family($gateway) === GatewayManager::DEFAULT_FAMILY) {
            return null;
        }

        return $this->createPerson($contact, $gateway);
    }

    /**
     * The family of a channel — 'whatsapp' for the phone-based providers.
     */
    protected function family(string $gateway): string
    {
        return app(GatewayManager::class)->family($gateway);
    }

    /**
     * Register a Person for a contact nobody has in the CRM yet.
     *
     * Goes through PersonRepository so Krayin builds unique_id and stores custom
     * attribute values exactly as it does for a contact created by hand.
     *
     * Never lets a failure here lose the message: the conversation is still
     * created, just unassigned.
     */
    protected function createPerson(ContactIdentity $contact, string $gateway): ?Model
    {
        // Stored without the leading "+", matching how the CRM already holds
        // numbers. Empty for a channel that has none.
        $digits = $contact->phone ? PhoneNumber::digits($contact->phone) : null;

        try {
            $person = app(PersonRepository::class)->create([
                // PersonRepository hands this straight to the attribute layer,
                // which keys custom attributes off entity_type.
                'entity_type' => 'persons',
                // A nameless Messenger contact still needs a label a human can
                // recognise in a list; the raw PSID is not one.
                'name'        => $contact->name
                    ?: ($digits ?: $this->leadSource($gateway).' '.substr((string) $contact->providerId, -6)),
                'emails' => [], // NOT NULL in the schema; a messaging contact has none
                // Only ever a real phone number. A channel id (a Messenger
                // PSID) written here would be matched against phone tails by
                // resolvePerson() and could bind a stranger to the wrong
                // contact, so a phone-less channel sends no numbers at all.
                ...($digits ? ['contact_numbers' => [['label' => 'work', 'value' => $digits]]] : []),
            ]);

            Log::info('Messaging: registered a new person for an unknown contact', [
                'person_id' => $person->id,
                'name'      => $person->name,
                'channel'   => $gateway,
                'phone'     => $digits,
            ]);

            return $person;
        } catch (Throwable $e) {
            Log::warning('Messaging: could not auto-create person', [
                'channel' => $gateway,
                'phone'   => $digits,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Name of the lead source a channel opens leads under. Declared per channel
     * in the registry so the pipeline reports "Messenger" and "WhatsApp"
     * separately, while both Kommo and Cloud API stay a single "WhatsApp".
     */
    protected function leadSource(string $gateway): string
    {
        return (string) config("whatsapp.gateways.{$gateway}.lead_source", 'WhatsApp');
    }

    /**
     * Match an incoming number against Person.contact_numbers. Uses a trailing
     * digit LIKE prefilter, then confirms in PHP against normalized values.
     */
    public function resolvePerson(string $phone): ?Model
    {
        $tail = PhoneNumber::tail($phone);

        if (strlen($tail) < 7) {
            return null;
        }

        return PersonProxy::modelClass()::where('contact_numbers', 'like', '%'.$tail.'%')
            ->get()
            ->first(function ($person) use ($tail) {
                foreach ((array) $person->contact_numbers as $number) {
                    $value = is_array($number) ? ($number['value'] ?? '') : $number;

                    if (str_ends_with(PhoneNumber::digits($value), $tail)) {
                        return true;
                    }
                }

                return false;
            });
    }
}
