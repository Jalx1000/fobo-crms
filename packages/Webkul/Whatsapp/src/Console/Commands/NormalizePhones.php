<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\PhoneNumber;

class NormalizePhones extends Command
{
    /**
     * @var string
     */
    protected $signature = 'whatsapp:normalize-phones {--apply : Persist the normalized numbers (otherwise dry-run)}';

    /**
     * @var string
     */
    protected $description = 'Normalize phone numbers to the canonical wa_id (full international, no +) across persons.contact_numbers and whatsapp_conversations.wa_phone, so incoming numbers resolve reliably (idempotent).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply ? 'Applying…' : 'Dry-run (nothing is written). Re-run with --apply to persist.');

        $this->normalizePersons($apply);
        $this->normalizeConversations($apply);

        return self::SUCCESS;
    }

    /**
     * persons.contact_numbers holds a JSON list of {label,value}. Rewrite each
     * value to the canonical wa_id so a number stored as "78164851" matches the
     * "59178164851" an inbound message arrives with.
     */
    protected function normalizePersons(bool $apply): void
    {
        $scanned = 0;
        $changed = 0;

        PersonProxy::modelClass()::query()
            ->whereNotNull('contact_numbers')
            ->chunkById(200, function ($people) use (&$scanned, &$changed, $apply) {
                foreach ($people as $person) {
                    $scanned++;

                    $updated = [];
                    $dirty = false;

                    foreach ((array) $person->contact_numbers as $number) {
                        if (is_array($number) && isset($number['value'])) {
                            $normalized = $this->canonical($number['value']);

                            if ($normalized !== '' && $normalized !== $number['value']) {
                                $dirty = true;
                                $number['value'] = $normalized;
                            }
                        }

                        $updated[] = $number;
                    }

                    if ($dirty) {
                        $changed++;

                        if ($apply) {
                            $person->update(['contact_numbers' => $updated]);
                        }
                    }
                }
            });

        $this->info(sprintf('persons          - scanned %d, %d %s.', $scanned, $changed, $apply ? 'updated' : 'would change'));
    }

    /**
     * whatsapp_conversations.wa_phone was historically stored with a leading '+'
     * (and, for old rows, sometimes without the country code). Rewrite it to the
     * same canonical wa_id the resolver now produces, so exact-match lookups by
     * phone keep working after the format change.
     */
    protected function normalizeConversations(bool $apply): void
    {
        $scanned = 0;
        $changed = 0;

        ConversationProxy::modelClass()::query()
            ->whereNotNull('wa_phone')
            ->chunkById(200, function ($conversations) use (&$scanned, &$changed, $apply) {
                foreach ($conversations as $conversation) {
                    $scanned++;

                    $normalized = $this->canonical($conversation->wa_phone);

                    if ($normalized === '' || $normalized === $conversation->wa_phone) {
                        continue;
                    }

                    // A sibling row may already own the canonical number (the
                    // same patient reached under "+" and non-"+" forms). Merging
                    // conversations is out of scope here, so skip the collision
                    // and report it instead of throwing on the unique index.
                    $taken = ConversationProxy::modelClass()::query()
                        ->where('wa_phone', $normalized)
                        ->where('id', '!=', $conversation->id)
                        ->exists();

                    if ($taken) {
                        $this->warn(sprintf('  conversation #%d: %s -> %s already exists, skipped.', $conversation->id, $conversation->wa_phone, $normalized));

                        continue;
                    }

                    $changed++;

                    if ($apply) {
                        $conversation->forceFill(['wa_phone' => $normalized])->save();
                    }
                }
            });

        $this->info(sprintf('conversations    - scanned %d, %d %s.', $scanned, $changed, $apply ? 'updated' : 'would change'));
    }

    /**
     * The canonical wa_id: digits only, no '+', country code prepended when the
     * local number lacks it. Same as ConversationResolver::phoneKey.
     */
    protected function canonical(?string $value): string
    {
        return PhoneNumber::withCountryCode($value, config('whatsapp.default_country_code'));
    }
}
