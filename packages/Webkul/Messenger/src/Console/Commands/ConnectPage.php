<?php

namespace Webkul\Messenger\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Messenger\Models\SocialConnection;

/**
 * Connect a Facebook page to the inbox.
 *
 * The page access token is the credential replies are sent with, so it is
 * stored encrypted in `social_connections` rather than in the environment:
 * several pages can be connected, and each carries its own token.
 */
class ConnectPage extends Command
{
    protected $signature = 'messenger:connect-page
        {page_id : Facebook Page id}
        {page_token : Long-lived page access token}
        {--name= : Friendly page name, shown in logs and diagnostics}';

    protected $description = 'Create or update the Messenger page connection used by the inbox.';

    public function handle(): int
    {
        $connection = SocialConnection::updateOrCreate(
            [
                'channel'    => 'messenger',
                'account_id' => (string) $this->argument('page_id'),
            ],
            [
                'name'         => $this->option('name') ?: null,
                'access_token' => (string) $this->argument('page_token'),
            ],
        );

        $this->info("Página conectada como social_connections #{$connection->id}.");
        $this->line('  Webhook: <info>php artisan whatsapp:webhook-info messenger</info>');

        return self::SUCCESS;
    }
}
