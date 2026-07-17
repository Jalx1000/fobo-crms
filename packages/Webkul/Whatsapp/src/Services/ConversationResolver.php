<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Models\ConversationProxy;

class ConversationResolver
{
    /**
     * Resolve the conversation for an inbound message.
     *
     * Identity is provider-dependent: Cloud API gives a phone, Kommo gives its
     * lead id (and the phone only after a lookup, which may fail). Match on
     * whichever is available, preferring the provider's own id since it is
     * exact.
     */
    public function resolve(ContactIdentity $contact, ?string $providerConversationId, string $gateway): ?Model
    {
        $model = ConversationProxy::modelClass();

        if ($providerConversationId) {
            // Key STRICTLY by the provider's id. Falling back to the phone here
            // would merge a second Kommo lead into the first lead's thread, and
            // replies would then be addressed to the wrong lead.
            $conversation = $model::where('gateway', $gateway)
                ->where('provider_conversation_id', $providerConversationId)
                ->first();
        } elseif ($contact->phone) {
            $conversation = $model::where('wa_phone', PhoneNumber::normalize($contact->phone))->first();
        } else {
            return null; // nothing to key on
        }

        if ($conversation) {
            return $this->backfill($conversation, $contact, $providerConversationId);
        }

        $person = $this->resolveOrCreatePerson($contact);

        return $model::create([
            'gateway'                  => $gateway,
            'wa_phone'                 => PhoneNumber::normalize($contact->phone) ?: null,
            'provider_conversation_id' => $providerConversationId,
            'wa_name'                  => $contact->name,
            'person_id'                => $person?->id,
            'lead_id'                  => $person ? optional($person->leads()->latest()->first())->id : null,
            'status'                   => $person ? 'open' : 'unassigned',
            'ai_enabled'               => true, // new conversations start with the agent on
        ]);
    }

    /**
     * Fill in details we learn later (the provider id on a phone-matched
     * conversation, a name, or a Person once the number gets registered).
     */
    protected function backfill(Model $conversation, ContactIdentity $contact, ?string $providerConversationId): Model
    {
        $updates = [];

        if ($providerConversationId && ! $conversation->provider_conversation_id) {
            $updates['provider_conversation_id'] = $providerConversationId;
        }

        if ($contact->name && ! $conversation->wa_name) {
            $updates['wa_name'] = $contact->name;
        }

        if ($contact->phone && ! $conversation->wa_phone) {
            $updates['wa_phone'] = PhoneNumber::normalize($contact->phone);
        }

        // A conversation may have been created before the phone was known (Kommo
        // does not send it), so it can still be waiting for its Person.
        if (! $conversation->person_id) {
            if ($person = $this->resolveOrCreatePerson($contact)) {
                $updates['person_id'] = $person->id;
                $updates['lead_id'] = optional($person->leads()->latest()->first())->id;
                $updates['status'] = 'open';
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
     * The Person behind an incoming number: the one already in the CRM, or a
     * newly registered one when the number is unknown.
     */
    public function resolveOrCreatePerson(ContactIdentity $contact): ?Model
    {
        if (! $contact->phone) {
            return null;
        }

        if ($person = $this->resolvePerson($contact->phone)) {
            return $person;
        }

        if (! config('whatsapp.auto_create_person')) {
            return null;
        }

        return $this->createPerson($contact);
    }

    /**
     * Register a Person for a number nobody has in the CRM yet.
     *
     * Goes through PersonRepository so Krayin builds unique_id and stores custom
     * attribute values exactly as it does for a contact created by hand.
     *
     * Never lets a failure here lose the message: the conversation is still
     * created, just unassigned.
     */
    protected function createPerson(ContactIdentity $contact): ?Model
    {
        // Stored without the leading "+", matching how the CRM already holds numbers.
        $digits = PhoneNumber::digits($contact->phone);

        try {
            $person = app(PersonRepository::class)->create([
                // PersonRepository hands this straight to the attribute layer,
                // which keys custom attributes off entity_type.
                'entity_type'     => 'persons',
                'name'            => $contact->name ?: $digits,
                'emails'          => [], // NOT NULL in the schema; a WhatsApp contact has none
                'contact_numbers' => [['label' => 'work', 'value' => $digits]],
            ]);

            Log::info('WhatsApp: registered a new person for an unknown number', [
                'person_id' => $person->id,
                'name'      => $person->name,
                'phone'     => $digits,
            ]);

            return $person;
        } catch (Throwable $e) {
            Log::warning('WhatsApp: could not auto-create person', [
                'phone' => $digits,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
