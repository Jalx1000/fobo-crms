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
         * El agente apagado deja al cliente sin nadie del otro lado: el asesor
         * ya no mira la conversación y el bot no puede hablar. Al medirlo había
         * 100 así, 98 sin ninguna derivación.
         *
         * Reemplaza a whatsapp:cerrar-derivaciones-inactivas, que sólo cubría
         * las derivaciones. El plazo se configura con
         * WHATSAPP_AI_REACTIVATE_HOURS (6 por defecto, 0 lo desactiva); cada
         * hora alcanza, y withoutOverlapping evita que dos corridas se pisen.
         */
        $schedule->command('whatsapp:reactivar-agente')
            ->hourly()
            ->withoutOverlapping();

        /*
         * Recordatorio a las 16 h y cierre en "Cliente sin interés" a las 72 h
         * cuando el cliente no contesta. No hace nada mientras
         * WHATSAPP_AUTO_CLOSE_SINCE esté vacío. Cada 15 minutos para que el
         * recordatorio salga cerca de las 16 h, con 8 h de ventana por delante.
         */
        $schedule->command('whatsapp:seguimiento-y-cierre')
            ->everyFifteenMinutes()
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
