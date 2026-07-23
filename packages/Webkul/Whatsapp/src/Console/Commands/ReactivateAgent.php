<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\StaffNumbers;

/**
 * Hands conversations back to the AI agent once the human take-over has gone
 * quiet for long enough.
 *
 * Turning the agent off is how an advisor takes a conversation, but nobody
 * remembers to turn it back on: conversations pile up unattended by the bot for
 * days. This re-enables them after the configured idle time.
 */
class ReactivateAgent extends Command
{
    /**
     * @var string
     */
    protected $signature = 'whatsapp:reactivate-agent {--dry-run : Show what would be reactivated without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Re-enable the AI agent on conversations handed to a human more than the configured idle time ago.';

    public function handle(): int
    {
        if (! config('whatsapp.ai.reactivate.enabled')) {
            $this->comment('Reactivación automática deshabilitada (whatsapp.ai.reactivate.enabled).');

            return self::SUCCESS;
        }

        // Minutes wins when set; hours stays as the fallback so a short test
        // value (2, 15) needs no fraction-of-an-hour arithmetic.
        $minutes = config('whatsapp.ai.reactivate.after_minutes');

        $minutes = $minutes !== null && $minutes !== ''
            ? max(1, (int) $minutes)
            : max(1, (int) round((float) config('whatsapp.ai.reactivate.after_hours', 1) * 60));

        $cutoff = now()->subMinutes($minutes);

        // Las derivaciones pedidas por el paciente esperan más: devolverle el bot
        // a alguien que pidió una persona contradice ese pedido.
        $minutosHandoff = max(1, (int) config('whatsapp.ai.reactivate.handoff_after_minutes', 180));
        $cutoffHandoff = now()->subMinutes($minutosHandoff);

        $dryRun = (bool) $this->option('dry-run');

        $prefix = DB::getTablePrefix();

        // Only conversations explicitly switched off (ai_enabled = 0). A NULL
        // ai_enabled means "inherit the global default" — it was never taken
        // over, so there is nothing to give back.
        //
        // ai_disabled_at is only stamped by the switch, so every conversation
        // turned off before it existed (or by a bulk update) carries NULL. They
        // must still come back, so updated_at stands in as the handoff time —
        // requiring the stamp is what kept this from reactivating anything.
        $conversations = ConversationProxy::modelClass()::query()
            ->where('ai_enabled', 0)
            ->whereRaw("COALESCE({$prefix}whatsapp_conversations.ai_disabled_at, {$prefix}whatsapp_conversations.updated_at) <= ?", [$cutoff])
            // The clock is the handoff, but a still-live chat must not have the
            // bot barge in: any traffic after the cutoff resets the wait.
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_message_at')
                    ->orWhere('last_message_at', '<=', $cutoff);
            })
            ->get()
            // Staff are deliberately off: the agent never handles a doctor, so
            // giving them back would only undo that on every run.
            ->reject(fn ($conversation) => StaffNumbers::isStaff($conversation->wa_phone))
            // Una derivación pedida por el paciente espera su propio plazo.
            ->reject(fn ($conversation) => $conversation->ai_handoff_at && $conversation->ai_handoff_at->gt($cutoffHandoff))
            ->values();

        if ($conversations->isEmpty()) {
            $this->info('Sin conversaciones para reactivar.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Conversaciones inactivas hace más de %d min: %d', $minutes, $conversations->count()));

        foreach ($conversations as $conversation) {
            $this->line(sprintf(
                '  %s %s (%s) — inactiva desde %s',
                $dryRun ? '→ [dry-run]' : '✓',
                $conversation->wa_name ?: 'sin nombre',
                $conversation->wa_phone,
                $conversation->ai_disabled_at ?: $conversation->updated_at,
            ));

            if (! $dryRun) {
                $conversation->forceFill([
                    'ai_enabled'     => true,
                    'ai_disabled_at' => null,
                    // También la marca de derivación: si no, un apagado posterior
                    // heredaría el plazo largo sin que nadie lo haya pedido.
                    'ai_handoff_at'  => null,
                ])->save();
            }
        }

        $this->info($dryRun ? 'Dry-run terminado.' : 'Reactivadas: '.$conversations->count());

        return self::SUCCESS;
    }
}
