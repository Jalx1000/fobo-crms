<?php

namespace Webkul\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\ShareMeDataService;

/**
 * Audita y corrige el vínculo entre doctors.unique_id (local) y el
 * physician._id del catálogo de disponibilidad de ShareMeData.
 *
 * checkAvailability() solo devuelve slots cuando doctors.unique_id es EXACTAMENTE
 * igual al physician._id de SMD. Los doctores creados por sync (Dropbox) pueden
 * quedar con un _id distinto (el del attendance/owner del evento), por lo que
 * nunca matchean y SMD "devuelve vacío". Este comando recorre la disponibilidad
 * real de SMD, machea cada doctor local por email (o nombre como fallback) y
 * corrige el unique_id.
 */
class LinkDoctorsSmd extends Command
{
    protected $signature = 'doctors:link-smd
        {--dry-run : Solo reporta diferencias, no escribe en la BD}
        {--days=14 : Ventana de días hacia adelante para consultar SMD}
        {--subsidiary=Santa Cruz : Sucursal a consultar en SMD}';

    protected $description = 'Vincula/corrige doctors.unique_id contra el physician._id real de ShareMeData';

    public function __construct(
        private ShareMeDataService $smd,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));
        $subsidiary = (string) $this->option('subsidiary');

        $from = now()->startOfDay()->format('Y-m-d H:i:s');
        $to = now()->addDays($days)->endOfDay()->format('Y-m-d H:i:s');

        // 1) Recolectar todos los physicians reales que expone SMD ─────────────
        $specialties = $this->smd->getSpecialties();
        if (empty($specialties)) {
            $specialties = DB::table('specialties')->pluck('name')->toArray();
        }

        if (empty($specialties)) {
            $this->error('No hay especialidades para consultar en SMD.');

            return self::FAILURE;
        }

        $byEmail = []; // email_lower => _id
        $byName = []; // "nombre apellido" normalizado => _id

        foreach ($specialties as $spec) {
            $this->smd->checkAvailability(null, $spec, $subsidiary, $from, $to);
            $raw = $this->smd->getLastResponse();

            if (! $raw || ! isset($raw['body']) || ! is_array($raw['body'])) {
                continue;
            }

            foreach ($raw['body'] as $item) {
                $p = $item['physician'] ?? null;
                $pid = $p['_id'] ?? null;
                if (! $pid) {
                    continue;
                }

                if (! empty($p['email'])) {
                    $byEmail[$this->norm($p['email'])] = $pid;
                }

                $fullName = $this->norm(($p['name'] ?? '').' '.($p['lastName'] ?? ''));
                if ($fullName !== '') {
                    $byName[$fullName] = $pid;
                }
            }
        }

        $this->info('Physicians distintos en SMD: '.count(array_unique(array_merge(
            array_values($byEmail),
            array_values($byName)
        ))));

        if (empty($byEmail) && empty($byName)) {
            $this->error('SMD no devolvió physicians. Revisa apikey / subsidiary / ventana de fechas.');

            return self::FAILURE;
        }

        // 2) Cotejar cada doctor local ────────────────────────────────────────
        $doctors = DB::table('doctors')->get(['id', 'name', 'email', 'unique_id']);

        $ok = $fixed = $notFound = $alreadyWrong = 0;

        foreach ($doctors as $d) {
            $pid = $this->resolvePhysicianId($d, $byEmail, $byName);

            if (! $pid) {
                $notFound++;
                $this->line(sprintf('  <fg=yellow>?</>  #%d %-28s  sin match en SMD (uid=%s)', $d->id, $this->trim28($d->name), $d->unique_id ?: '∅'));

                continue;
            }

            if ($d->unique_id === $pid) {
                $ok++;

                continue;
            }

            $alreadyWrong++;
            $this->line(sprintf(
                '  <fg=red>✗</>  #%d %-28s  uid actual=%s  ->  SMD=%s',
                $d->id,
                $this->trim28($d->name),
                $d->unique_id ?: '∅',
                $pid
            ));

            if (! $dryRun) {
                DB::table('doctors')->where('id', $d->id)->update([
                    'unique_id'  => $pid,
                    'updated_at' => now(),
                ]);
                $fixed++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Total: %d | Ya correctos: %d | Desalineados: %d | %s | Sin match SMD: %d',
            $doctors->count(),
            $ok,
            $alreadyWrong,
            $dryRun ? 'Corregibles (dry-run, no escrito)' : "Corregidos: {$fixed}",
            $notFound
        ));

        if ($dryRun && $alreadyWrong > 0) {
            $this->comment('Corré sin --dry-run para aplicar los cambios.');
        }

        return self::SUCCESS;
    }

    /**
     * Match por email exacto (preferido); fallback a nombre: todas las palabras
     * significativas del nombre local contenidas en algún physician de SMD.
     */
    private function resolvePhysicianId(object $doctor, array $byEmail, array $byName): ?string
    {
        if (! empty($doctor->email)) {
            $hit = $byEmail[$this->norm($doctor->email)] ?? null;
            if ($hit) {
                return $hit;
            }
        }

        $localName = $this->norm($doctor->name);
        if ($localName === '') {
            return null;
        }

        // Coincidencia exacta de nombre completo
        if (isset($byName[$localName])) {
            return $byName[$localName];
        }

        // Coincidencia por subconjunto de palabras (>2 chars), p.ej.
        // "adriana soria" ⊂ "adriana soria aguila"
        $localWords = array_filter(explode(' ', $localName), fn ($w) => mb_strlen($w) > 2);
        if (empty($localWords)) {
            return null;
        }

        $matchId = null;
        $matches = 0;
        foreach ($byName as $smdName => $pid) {
            $contained = true;
            foreach ($localWords as $w) {
                if (! str_contains($smdName, $w)) {
                    $contained = false;
                    break;
                }
            }
            if ($contained) {
                $matches++;
                $matchId = $pid;
            }
        }

        // Solo aceptar si es inequívoco (un único physician contiene esas palabras)
        return $matches === 1 ? $matchId : null;
    }

    private function norm(?string $s): string
    {
        return trim(mb_strtolower((string) $s));
    }

    private function trim28(?string $s): string
    {
        return mb_substr((string) $s, 0, 28);
    }
}
