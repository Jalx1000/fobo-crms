<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Webkul\Admin\Support\SmdSyncState;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('inbound-emails:process')->everyFiveMinutes();

        // Red de seguridad del webhook: el webhook de Dropbox es el camino primario
        // (near-real-time); este poll solo levanta lo que un webhook perdido no haya
        // capturado, por eso va espaciado (antes cada 5 min quemaba recursos).
        // Respeta el flag de pausa (toggle desde la UI). withoutOverlapping: una
        // corrida lenta no debe solaparse con la siguiente.
        $schedule->command('smd:sync-dropbox --days=2')
            ->everyThirtyMinutes()
            ->withoutOverlapping(10)
            ->when(fn () => ! SmdSyncState::isPaused());
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
