<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->shareStorageBetweenProcesses();
    }

    /**
     * Let PHP-FPM and the queue worker write into each other's directories.
     *
     * Two processes write to storage/app/public — PHP-FPM as www-data (outbound
     * attachments) and the queue worker as root (inbound WhatsApp/Messenger
     * media) — so whichever one creates a conversation's folder must leave it
     * writable for the other. config/filesystems.php asks for 0775 directories,
     * but PHP's mkdir() masks that with the process umask (022), so the folders
     * were silently created 0755 and the second process got "Permission denied"
     * for every attachment.
     *
     * umask 0002 keeps the group bit the configuration already intends. The
     * group itself comes from the setgid bit on storage/app/public, so files
     * land under www-data whoever writes them.
     */
    protected function shareStorageBetweenProcesses(): void
    {
        umask(0002);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
