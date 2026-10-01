<?php

namespace Webkul\Admin\DataGrids\Concerns;

/**
 * Makes a grid's free-text search find phone numbers regardless of how they
 * were typed.
 *
 * Numbers are stored bare ("59170650945") while users type them with
 * separators ("706-50945", "70 650 945"), so the raw term alone never matches.
 * The base implementation ORs every value of the "all" filter together, so
 * pushing a digit-only variant in is enough to cover both spellings. A plain
 * substring match already handles the omitted "591" country code.
 */
trait NormalizesContactSearch
{
    /**
     * Shortest run of digits still treated as a phone number rather than as a
     * number that happens to appear in a name or id.
     */
    protected int $minSearchableDigits = 6;

    /**
     * {@inheritdoc}
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function processRequestedFilters(array $requestedFilters)
    {
        foreach ($requestedFilters['all'] ?? [] as $value) {
            $digits = preg_replace('/\D/', '', (string) $value);

            if (
                strlen($digits) >= $this->minSearchableDigits
                && $digits !== $value
            ) {
                $requestedFilters['all'][] = $digits;
            }
        }

        return parent::processRequestedFilters($requestedFilters);
    }
}
