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

        // Agenda única de Tessa: mantiene una ventana deslizante de turnos a
        // futuro (horarios de atención, domingos cerrado). Idempotente.
        $schedule->command('tessa:generate-shifts --days=60')
            ->dailyAt('00:30')
            ->withoutOverlapping();

        // Red de seguridad del webhook: el webhook de Dropbox es el camino primario
        // (near-real-time); este poll solo levanta lo que un webhook perdido no haya
        // capturado, por eso va espaciado (antes cada 5 min quemaba recursos).
        // Respeta el flag de pausa (toggle desde la UI). withoutOverlapping: una
        // corrida lenta no debe solaparse con la siguiente.
        // Tessa no usa ShareMeData: el sync solo corre si la feature está activa.
        $schedule->command('smd:sync-dropbox --days=2')
            ->everyThirtyMinutes()
            ->withoutOverlapping(10)
            ->when(fn () => config('tessa.features.insurance_smd', false) && ! SmdSyncState::isPaused());
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
