<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Contact\Models\PersonProxy;
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
    protected $description = 'Normalize Person.contact_numbers to E.164 so incoming WhatsApp numbers resolve reliably (idempotent).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $model = PersonProxy::modelClass();

        $scanned = 0;
        $changed = 0;

        $model::query()
            ->whereNotNull('contact_numbers')
            ->chunkById(200, function ($people) use (&$scanned, &$changed, $apply) {
                foreach ($people as $person) {
                    $scanned++;

                    $numbers = (array) $person->contact_numbers;
                    $updated = [];
                    $dirty = false;

                    foreach ($numbers as $number) {
                        if (is_array($number) && isset($number['value'])) {
                            $normalized = PhoneNumber::normalize($number['value']);

                            if ($normalized !== '' && $normalized !== $number['value']) {
                                $dirty = true;
                            }

                            $number['value'] = $normalized !== '' ? $normalized : $number['value'];
                            $updated[] = $number;
                        } else {
                            $updated[] = $number;
                        }
                    }

                    if ($dirty) {
                        $changed++;

                        if ($apply) {
                            $person->update(['contact_numbers' => $updated]);
                        }
                    }
                }
            });

        $this->info(sprintf(
            '%s — scanned %d person(s), %d would change%s.',
            $apply ? 'Applied' : 'Dry-run',
            $scanned,
            $changed,
            $apply ? ' (persisted)' : ''
        ));

        if (! $apply && $changed > 0) {
            $this->comment('Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
