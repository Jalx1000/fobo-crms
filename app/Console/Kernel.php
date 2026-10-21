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

        /*
        | Recontacto antes de que se cierre la ventana de 24 h.
        |
        | Cada diez minutos: la franja de disparo dura una hora entera, así que
        | diez minutos de resolución alcanzan de sobra y el margen de error es
        | pequeño frente a esa hora. Cada minuto sería sondear sesenta veces
        | para una decisión que no cambia tan rápido.
        */
        $schedule->command('crm:recontactar-antes-de-cerrar')->everyTenMinutes()->withoutOverlapping();

        /*
        | Leads cuyo cliente no escribe hace LEADS_SIN_INTERES_DIAS días pasan a
        | «Sin interés». Cada hora alcanza: el umbral se mide en días.
        */
        $schedule->command('crm:leads-sin-interes')->hourly()->withoutOverlapping();
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
