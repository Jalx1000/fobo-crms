<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('inbound-emails:process')->everyFiveMinutes();

        // Derivaciones que nadie tomó: mientras tanto el cliente está esperando
        // en silencio, porque el agente deja de responder al derivar.
        $schedule->command('crm:escalar-handoffs')->everyFiveMinutes()->withoutOverlapping();

        /*
         * Cada cuarto de hora alcanza: el plazo se mide en horas, así que el
         * retraso máximo es despreciable frente a él y la corrida es barata
         * (solo mira las derivaciones vigentes, que son unas pocas).
         */
        $schedule->command('crm:cerrar-handoffs-abandonados')->everyFifteenMinutes()->withoutOverlapping();
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
