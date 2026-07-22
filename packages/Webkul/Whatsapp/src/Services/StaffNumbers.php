<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;

/**
 * Numbers that belong to the clinic's own staff (doctors), not to patients.
 *
 * The AI agent exists to answer patients; a doctor writing to the clinic must
 * always reach a human. So staff numbers are treated like [[AgentBlocklist]]:
 * the agent never applies to them, whatever the global default says.
 *
 * This also unblocks the composer for them — with the agent considered active,
 * the whole row (including the template button) is locked.
 */
class StaffNumbers
{
    /**
     * Same trailing-digit rule as the rest of the pipeline: doctors are typed in
     * by reception, usually as 8 local digits, while conversations key on the
     * canonical wa_id.
     */
    protected const MATCH_DIGITS = 8;

    /**
     * Memoized per request: aiEffective() runs once per row of the conversation
     * list, and this must not become a query per row.
     *
     * @var array<int, string>|null
     */
    protected static ?array $tails = null;

    public static function isStaff(?string $phone): bool
    {
        $tail = PhoneNumber::tail($phone, self::MATCH_DIGITS);

        return $tail !== '' && in_array($tail, self::tails(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function tails(): array
    {
        if (self::$tails !== null) {
            return self::$tails;
        }

        $tails = [];

        foreach (DB::table('doctors')->whereNotNull('phone')->pluck('phone') as $phone) {
            $tail = PhoneNumber::tail($phone, self::MATCH_DIGITS);

            if ($tail !== '') {
                $tails[] = $tail;
            }
        }

        return self::$tails = array_values(array_unique($tails));
    }

    /**
     * Drop the memo — for tests and for long-running workers that would
     * otherwise never see a newly loaded doctor number.
     */
    public static function flush(): void
    {
        self::$tails = null;
    }
}
