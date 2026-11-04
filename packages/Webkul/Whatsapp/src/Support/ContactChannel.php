<?php

namespace Webkul\Whatsapp\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Gateways\GatewayManager;

/**
 * The messaging channel a CRM contact is reachable on, for use inside DataGrids.
 *
 * Lives in one place because three grids consume it. The same SQL copied three
 * times is three copies that drift: this module already carries two different
 * versions of the "prefer the active gateway" tie-break, one in InboxController
 * and one in DocumentDispatcher.
 */
class ContactChannel
{
    /**
     * Filter value for a contact with no conversation at all.
     *
     * A dropdown filter builds `where(column, value)`, and that never matches a
     * NULL - so the "no channel" case has to be an actual value, produced by the
     * COALESCE in column(). Without it the majority of hand-entered contacts
     * would only ever show up with the filter cleared.
     */
    public const NONE = 'none';

    /**
     * Alias of the joined subquery. Laravel prefixes join aliases like any other
     * table, so raw references to it must go through qualified().
     */
    protected const ALIAS = 'contact_channel';

    /**
     * Join one row per contact carrying the channel to reach them on.
     *
     * ONE row per contact by construction (ROW_NUMBER() = 1), not by luck: of
     * the three grids only Leads has a GROUP BY, so a join that multiplied would
     * silently show the same contact several times in the other two - a bug that
     * reads as bad data rather than bad code, and raises no error at all.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $personIdColumn  Qualified column to match on, e.g. 'persons.id'.
     */
    public static function joinTo($query, string $personIdColumn): void
    {
        $query->leftJoinSub(
            DB::query()->fromSub(self::rankedConversations(), 'ranked')
                ->select('person_id', 'gateway')
                ->where('rn', 1),
            self::ALIAS,
            fn ($join) => $join->on(self::ALIAS.'.person_id', '=', $personIdColumn)
        );
    }

    /**
     * Every conversation, ranked so the first one per contact is the channel to
     * reach them on.
     *
     * Same tie-break as the inbox: the active gateway wins, because it is the
     * only one anyone can actually write through. Recency alone would report a
     * channel that was decommissioned months ago.
     *
     * Shared by joinTo() and applyFilter() so the grid and the kanban board can
     * never disagree about which channel a contact belongs to.
     */
    protected static function rankedConversations(): \Illuminate\Database\Query\Builder
    {
        return DB::table('whatsapp_conversations')
            ->selectRaw(
                'person_id, gateway, ROW_NUMBER() OVER (
                    PARTITION BY person_id
                    ORDER BY (gateway = ?) DESC, last_message_at DESC, id DESC
                ) as rn',
                [(string) config('whatsapp.gateway')]
            )
            ->whereNotNull('person_id');
    }

    /**
     * Narrow a query to contacts reachable on one channel.
     *
     * For query builders that are not DataGrids - the leads kanban board hits a
     * repository endpoint, not the grid - so it cannot use the joined column and
     * needs the same rule expressed as a constraint.
     *
     * Deliberately built on the SAME ranked subquery as joinTo(): a contact must
     * not land in "WhatsApp" on the board and in "Messenger" on the grid.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  string|null  $value  A gateway key, self::NONE, or null for no filtering.
     * @param  string  $personIdColumn  Qualified column, e.g. 'leads.person_id'.
     */
    public static function applyFilter($query, ?string $value, string $personIdColumn): void
    {
        if (! $value) {
            return;
        }

        if ($value === self::NONE) {
            $query->where(function ($scope) use ($personIdColumn) {
                // A lead with no contact at all has no channel either, and the
                // salesperson filtering for "sin canal" means to see both.
                $scope->whereNull($personIdColumn)
                    ->orWhereNotIn($personIdColumn, function ($sub) {
                        // whereNotIn returns nothing when the subquery yields a
                        // NULL, so they are excluded here rather than relied on.
                        $sub->from('whatsapp_conversations')
                            ->whereNotNull('person_id')
                            ->select('person_id');
                    });
            });

            return;
        }

        $query->whereIn($personIdColumn, function ($sub) use ($value) {
            $sub->fromSub(self::rankedConversations(), 'ranked')
                ->select('person_id')
                ->where('rn', 1)
                ->where('gateway', $value);
        });
    }

    /**
     * The expression to select and to filter on.
     *
     * COALESCE, not the bare column: see NONE.
     */
    public static function column(): Expression
    {
        return DB::raw('COALESCE('.self::qualified('gateway').", '".self::NONE."')");
    }

    /**
     * The same expression, aliased for the SELECT clause.
     */
    public static function selectColumn(): Expression
    {
        return DB::raw('COALESCE('.self::qualified('gateway').", '".self::NONE."') as platform");
    }

    /**
     * Dropdown options: every registered channel, plus "no channel".
     *
     * Read from the registry rather than hardcoded, so plugging in a channel
     * makes it filterable without anyone remembering to edit three grids.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function options(): array
    {
        $channels = array_map(fn (array $channel) => [
            'label' => $channel['label'],
            'value' => $channel['key'],
        ], app(GatewayManager::class)->channels());

        $channels[] = [
            'label' => trans('admin::app.common.no-channel'),
            'value' => self::NONE,
        ];

        return $channels;
    }

    /**
     * Human label for a stored value, for the grid cell.
     */
    public static function label(?string $gateway): string
    {
        if (! $gateway || $gateway === self::NONE) {
            return trans('admin::app.common.no-channel');
        }

        return app(GatewayManager::class)->label($gateway);
    }

    /**
     * Qualify a column of the joined subquery, honouring the table prefix.
     *
     * DB::table() applies DB_PREFIX on its own; raw SQL does not, and this
     * install runs with DB_PREFIX=kl_.
     */
    protected static function qualified(string $column): string
    {
        return DB::getTablePrefix().self::ALIAS.'.'.$column;
    }
}
