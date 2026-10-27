<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera la agenda del único recurso de Tessa (un operador por spa) a partir de
 * los horarios de atención (config/tessa.php), saltando domingos (horario null).
 *
 * Idempotente: crea el doctor "Agenda Tessa" si no existe y agrega un turno por
 * día abierto solo si no hay ya uno para ese doctor+fecha. Pensado para correr
 * a diario desde el scheduler (ventana deslizante).
 */
class TessaGenerateShifts extends Command
{
    protected $signature = 'tessa:generate-shifts
        {--days=60 : Cantidad de días a generar desde la fecha de inicio}
        {--from= : Fecha de inicio (Y-m-d). Default: hoy}';

    protected $description = 'Genera turnos de la agenda única de Tessa según los horarios de atención (domingos cerrado)';

    public function handle(): int
    {
        $name  = (string) config('tessa.agenda_doctor_name', 'Agenda Tessa');
        $hours = (array) config('tessa.business_hours', []);
        $days  = max(1, (int) $this->option('days'));
        $from  = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : Carbon::today();

        // 1. Asegurar el único recurso agendable (el operador/cabina de Tessa).
        $doctor = DB::table('doctors')->where('name', $name)->first();
        if (! $doctor) {
            $doctorId = DB::table('doctors')->insertGetId([
                'name'       => $name,
                'is_active'  => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Creado recurso agendable «{$name}» (id {$doctorId}).");
        } else {
            $doctorId = $doctor->id;
        }

        // 2. Generar turnos día a día, saltando los cerrados (domingo = null).
        $created = 0;
        $skippedClosed = 0;
        $existing = 0;
        $cursor = $from->copy();

        for ($i = 0; $i < $days; $i++, $cursor->addDay()) {
            $window = $hours[$cursor->dayOfWeek] ?? null;

            if (! $window) {
                $skippedClosed++;

                continue;
            }

            [$start, $end] = $window;
            $date = $cursor->toDateString();

            $already = DB::table('doctor_shifts')
                ->where('doctor_id', $doctorId)
                ->where('date', $date)
                ->exists();

            if ($already) {
                $existing++;

                continue;
            }

            DB::table('doctor_shifts')->insert([
                'doctor_id'  => $doctorId,
                'date'       => $date,
                'start_time' => $start,
                'end_time'   => $end,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        $this->info("Turnos creados: {$created} · ya existían: {$existing} · días cerrados (domingos): {$skippedClosed}.");

        return self::SUCCESS;
    }
}
