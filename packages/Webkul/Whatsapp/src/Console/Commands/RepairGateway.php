<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Conversations record the gateway they were created under, and SendMessage
 * honours that record rather than the active config. That is deliberate — but it
 * means conversations created while WHATSAPP_GATEWAY pointed at the wrong
 * provider stay broken forever, silently routing to a driver that cannot address
 * them. This repairs them.
 */
class RepairGateway extends Command
{
    /**
     * @var string
     */
    protected $signature = 'whatsapp:repair-gateway
                            {--from= : Gateway currently recorded on the conversations}
                            {--to= : Gateway they should have (defaults to the active one)}
                            {--apply : Persist the change (otherwise dry-run)}';

    /**
     * @var string
     */
    protected $description = 'Re-point conversations stamped with the wrong gateway (e.g. created while WHATSAPP_GATEWAY was misconfigured).';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to') ?: (string) config('whatsapp.gateway');
        $apply = (bool) $this->option('apply');

        if (! $from) {
            $this->error('  Indicá --from=<gateway> (el gateway mal grabado).');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('  --from y --to son iguales; no hay nada que reparar.');

            return self::FAILURE;
        }

        $model = ConversationProxy::modelClass();

        $query = $model::where('gateway', $from);

        // A conversation with a provider id is genuinely owned by that provider;
        // re-pointing it would address the wrong entity. Leave those alone.
        $owned = (clone $query)->whereNotNull('provider_conversation_id')->count();
        $repairable = (clone $query)->whereNull('provider_conversation_id');
        $count = $repairable->count();

        $this->newLine();
        $this->line("  Conversaciones con gateway='<comment>{$from}</comment>': ".(clone $query)->count());
        $this->line("    · reparables (sin provider_conversation_id): <info>{$count}</info>");

        if ($owned) {
            $this->line("    · <comment>{$owned} omitidas</comment>: tienen provider_conversation_id, pertenecen de verdad a {$from}");
        }

        if (! $count) {
            $this->newLine();
            $this->info('  Nada que reparar.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->line("  Dry-run: pasarían a gateway='<info>{$to}</info>'.");
            $this->comment('  Re-ejecutá con --apply para persistir.');

            return self::SUCCESS;
        }

        $repairable->update(['gateway' => $to]);

        $this->newLine();
        $this->info("  ✔ {$count} conversación(es) re-apuntadas a '{$to}'.");

        return self::SUCCESS;
    }
}
