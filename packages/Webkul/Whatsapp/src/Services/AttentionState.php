<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who has to answer each conversation, for the list badges and the «Atender»
 * filter.
 *
 * - ia:         the agent is handling it.
 * - human:      a person took over and already answered.
 * - derived:    handed to reception (handoff or Recepcionista stage) and no
 *               person has answered yet.
 * - asks_human: the patient asked for a person in the last 24 h and nobody
 *               answered after that. The safety net for an agent that says
 *               «le paso con una recepcionista» without calling the handoff.
 */
class AttentionState
{
    public const IA = 'ia';

    public const HUMAN = 'human';

    public const DERIVED = 'derived';

    public const ASKS_HUMAN = 'asks_human';

    /**
     * Adds the two timestamps status() needs, as subselects (one query for
     * the whole page, not one per row).
     */
    public static function withColumns(Builder $query): Builder
    {
        return $query
            ->selectSub(self::humanReplies()->selectRaw('MAX(created_at)'), 'last_human_out_at')
            ->selectSub(self::humanRequests()->selectRaw('MAX(created_at)'), 'asks_human_at');
    }

    /**
     * Only the conversations that need a person (derived or asks_human).
     */
    public static function onlyNeedingAttention(Builder $query): Builder
    {
        $receptionPersons = array_keys(ReceptionStage::personIds());

        return $query->where(function ($q) use ($receptionPersons) {
            // Handed off and nobody answered since.
            $q->where(fn ($d) => $d->whereNotNull('whatsapp_conversations.ai_handoff_at')
                ->whereNotExists(self::humanReplies()->whereColumn('created_at', '>=', 'whatsapp_conversations.ai_handoff_at')));

            // In the Recepcionista stage, waiting on the patient's last message.
            if ($receptionPersons) {
                $q->orWhere(fn ($r) => $r->whereIn('whatsapp_conversations.person_id', $receptionPersons)
                    ->whereRaw('('.self::lastDirection().") = 'inbound'"));
            }

            // Asked for a person and nobody answered after that message.
            $q->orWhereExists(
                self::humanRequests('asked')->whereNotExists(
                    self::humanReplies('reply')->whereColumn('reply.created_at', '>', 'asked.created_at')
                )
            );
        });
    }

    /**
     * The badge of one row built with withColumns(). $lastDirection is the
     * direction of the latest message, already selected by the list.
     */
    public static function status($conversation, ?string $lastDirection): string
    {
        $lastHuman = $conversation->last_human_out_at;
        $answeredAfter = fn ($at) => $lastHuman && $at && strtotime($lastHuman) >= strtotime((string) $at);

        $inReception = ReceptionStage::holds($conversation->lead_id, $conversation->person_id);

        if (($conversation->ai_handoff_at && ! $answeredAfter($conversation->ai_handoff_at))
            || ($inReception && $lastDirection === 'inbound')) {
            return self::DERIVED;
        }

        if ($conversation->asks_human_at && ! $answeredAfter($conversation->asks_human_at)) {
            return self::ASKS_HUMAN;
        }

        return $conversation->aiEffective() ? self::IA : self::HUMAN;
    }

    /**
     * Messages a person (not the agent, not a reminder) sent in this
     * conversation.
     */
    protected static function humanReplies(string $alias = 'whatsapp_messages'): QueryBuilder
    {
        return DB::table("whatsapp_messages as {$alias}")
            ->whereColumn("{$alias}.conversation_id", 'whatsapp_conversations.id')
            ->where("{$alias}.direction", 'outbound')
            ->where("{$alias}.sender", 'human');
    }

    /**
     * Patient messages of the last 24 h that ask for a person.
     */
    protected static function humanRequests(string $alias = 'whatsapp_messages'): QueryBuilder
    {
        $keywords = (array) config('whatsapp.ai.human_request_keywords', []);

        return DB::table("whatsapp_messages as {$alias}")
            ->whereColumn("{$alias}.conversation_id", 'whatsapp_conversations.id')
            ->where("{$alias}.direction", 'inbound')
            ->where("{$alias}.created_at", '>=', now()->subDay())
            ->where(function ($q) use ($alias, $keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere("{$alias}.body", 'like', '%'.$keyword.'%');
                }

                if (! $keywords) {
                    $q->whereRaw('1 = 0');
                }
            });
    }

    protected static function lastDirection(): string
    {
        $prefix = DB::getTablePrefix();

        return "select direction from {$prefix}whatsapp_messages where conversation_id = {$prefix}whatsapp_conversations.id order by id desc limit 1";
    }
}
