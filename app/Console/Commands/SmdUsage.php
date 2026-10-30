<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Admin\Services\Smd\ApiBudget;

/**
 * Consumo de la API de ShareMeData (contrato: 10.000 consultas por mes).
 *
 *   php artisan smd:usage            # hoy, por origen
 *   php artisan smd:usage --days=7   # últimos 7 días + el de hoy por origen
 */
class SmdUsage extends Command
{
    protected $signature = 'smd:usage {--days=1 : Días hacia atrás a mostrar}';

    protected $description = 'Consultas hechas a la API de ShareMeData por día, por mes y por origen';

    public function handle(ApiBudget $budget): int
    {
        $tz = config('app.timezone');
        $days = max(1, (int) $this->option('days'));
        $rows = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now($tz)->subDays($i)->toDateString();
            $rows[] = [$date, $budget->day($date), $budget->blocked($date)];
        }

        $cap = (int) config('smd.api_budget.daily_cap', 800);
        $this->table(['Día', 'Consultas', 'Bloqueadas por el tope'], $rows);
        $this->line("Tope diario: <info>{$cap}</info> (pasado el tope solo salen las esenciales: crear/editar citas y fichas de Dropbox).");

        $month = now($tz)->format('Y-m');
        $this->line("Mes {$month}: <info>{$budget->month($month)}</info> de 10.000 contratadas (aviso a las ".config('smd.api_budget.monthly_alert', 8000).').');

        $today = now($tz)->toDateString();
        $sources = $budget->bySource($today);

        if ($sources) {
            $this->newLine();
            $this->line("Hoy ({$today}) por origen:");
            $this->table(['Origen → método', 'Consultas'], collect($sources)->map(fn ($n, $k) => [$k, $n])->values()->all());
        }

        return self::SUCCESS;
    }
}
