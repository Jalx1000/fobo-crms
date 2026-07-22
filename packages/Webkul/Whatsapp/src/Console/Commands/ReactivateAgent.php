<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Models\ConversationProxy;

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

        $hours = (float) config('whatsapp.ai.reactivate.after_hours', 1);
        $cutoff = now()->subMinutes((int) round($hours * 60));
        $dryRun = (bool) $this->option('dry-run');

        // Only conversations explicitly switched off (ai_enabled = 0) and stamped
        // by the switch. A NULL ai_enabled means "inherit the global default" —
        // it was never taken over, so there is nothing to give back.
        $conversations = ConversationProxy::modelClass()::query()
            ->where('ai_enabled', 0)
            ->whereNotNull('ai_disabled_at')
            ->where('ai_disabled_at', '<=', $cutoff)
            // The clock is the handoff, but a still-live chat must not have the
            // bot barge in: any traffic after the cutoff resets the wait.
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_message_at')
                    ->orWhere('last_message_at', '<=', $cutoff);
            })
            ->get();

        if ($conversations->isEmpty()) {
            $this->info('Sin conversaciones para reactivar.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Conversaciones inactivas hace más de %s h: %d', $hours, $conversations->count()));

        foreach ($conversations as $conversation) {
            $this->line(sprintf(
                '  %s %s (%s) — apagado %s',
                $dryRun ? '→ [dry-run]' : '✓',
                $conversation->wa_name ?: 'sin nombre',
                $conversation->wa_phone,
                $conversation->ai_disabled_at,
            ));

            if (! $dryRun) {
                $conversation->forceFill([
                    'ai_enabled'     => true,
                    'ai_disabled_at' => null,
                ])->save();
            }
        }

        $this->info($dryRun ? 'Dry-run terminado.' : 'Reactivadas: '.$conversations->count());

        return self::SUCCESS;
    }
}
