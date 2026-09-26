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
        /*
         * Desactivado el 2026-09-22 al poner el scheduler en marcha.
         *
         * Estaba agendado desde siempre, pero NADA lo ejecutaba: no había cron
         * ni un schedule:run en el contenedor, y por eso la tabla `emails`
         * tiene 0 filas. Al prender el scheduler pasó a correr de verdad y
         * falla en cada intento con "[AUTHENTICATIONFAILED] Authentication
         * failed": las credenciales IMAP no sirven.
         *
         * Cada 5 minutos serían 288 stack traces por día sobre un laravel.log
         * que ya pesa 3.3 GB. Se reactiva arreglando las credenciales y
         * comprobando que `php artisan inbound-emails:process` termine bien.
         */
        // $schedule->command('inbound-emails:process')->everyFiveMinutes();

        /*
         * Una derivación abierta silencia al agente (Conversation::aiEffective),
         * así que una que nadie cierra deja al cliente sin nadie del otro lado:
         * el asesor ya no la mira y el bot no puede hablar. Cada hora alcanza —
         * el corte es de 24 h— y withoutOverlapping evita que dos corridas se
         * pisen si una se cuelga.
         */
        $schedule->command('whatsapp:cerrar-derivaciones-inactivas')
            ->hourly()
            ->withoutOverlapping();
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
