<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\Smd\SmdAgenda;
use Webkul\Admin\Services\Smd\SmdAlerts;

/**
 * Chequeo diario de los doctores contra SMD (planning/16, RF-9):
 *
 *  - A-6: activo y vinculado a SMD, pero sin especialidad en Sofo → no
 *    aparece en ninguna especialidad del formulario de WhatsApp.
 *  - A-7: activo, pero su `unique_id` no está en la agenda "General" de SMD
 *    → no se le ven horarios. SMD incluye a los médicos aunque no tengan
 *    horarios en el rango (V-17), así que no salta por falta de agenda.
 *
 * Una sola consulta (la agenda de todos a 14 días). Los ids no se corrigen
 * acá: se cargan a mano (D-9).
 */
class SmdCheckDoctors extends Command
{
    protected $signature = 'smd:check-doctors
        {--days=14 : Días de agenda que se piden a SMD}';

    protected $description = 'Avisa por correo de doctores sin especialidad en Sofo o que SMD no publica';

    public function handle(SmdAgenda $agenda, SmdAlerts $alerts): int
    {
        $doctors = DB::table('doctors')
            ->where('is_active', 1)
            ->whereNotNull('unique_id')
            ->where('unique_id', '!=', '')
            ->get(['id', 'name', 'unique_id']);

        $withSpecialty = DB::table('doctor_specialty')->whereIn('doctor_id', $doctors->pluck('id'))->distinct()->pluck('doctor_id')->all();
        $withoutSpecialty = $doctors->reject(fn ($d) => in_array($d->id, $withSpecialty));

        $published = $agenda->all(now(config('app.timezone'))->startOfDay(), max(1, (int) $this->option('days')));

        if ($published === null) {
            // La falla de SMD ya la reporta ShareMeDataService (A-1/A-2).
            $this->error('SMD no devolvió la agenda: no se pudo revisar qué doctores publica.');
        }

        $notInSmd = $published === null
            ? collect()
            : $doctors->reject(fn ($d) => array_key_exists((string) $d->unique_id, $published));

        $label = fn ($d) => '#'.$d->id.' '.trim((string) $d->name);

        if ($withoutSpecialty->isNotEmpty()) {
            $alerts->report(SmdAlerts::DOCTOR_WITHOUT_SPECIALTY, [
                'doctores' => $withoutSpecialty->map($label)->implode(', '),
            ]);
        }

        if ($notInSmd->isNotEmpty()) {
            $alerts->report(SmdAlerts::DOCTOR_NOT_IN_SMD, [
                'especialidad_smd' => (string) config('smd.availability_specialty', 'General'),
                'doctores'         => $notInSmd->map(fn ($d) => $label($d).' ('.$d->unique_id.')')->implode(', '),
            ]);
        }

        $this->table(['Chequeo', 'Doctores'], [
            ['A-6 sin especialidad en Sofo', $withoutSpecialty->map($label)->implode("\n") ?: '—'],
            ['A-7 no aparecen en SMD', $published === null ? 'sin datos de SMD' : ($notInSmd->map($label)->implode("\n") ?: '—')],
        ]);

        return $published === null ? self::FAILURE : self::SUCCESS;
    }
}
