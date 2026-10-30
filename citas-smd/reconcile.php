<?php

/**
 * DRY-RUN de reconciliación de doctores SMD (solo lectura).
 *
 * Fuente de verdad: los exports mensuales de SMD en citas-smd/*.json.
 * Para cada evento se re-deriva el/los médico(s) con la MISMA lógica que la
 * sync (normalizeDropbox + resolveDoctor sin crear) y se compara contra lo que
 * la cita tiene hoy en el CRM. Reporta desajustes sin escribir nada.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Webkul\Doctor\Repositories\DoctorRepository;

$svc = app(\Webkul\Admin\Services\IncomingAppointmentService::class);
$mNorm = new ReflectionMethod($svc, 'normalizeDropbox');
$mNorm->setAccessible(true);

$repo = app(DoctorRepository::class);

/** Lookup SIN crear: replica pasos 1-3 de resolveDoctor. Devuelve id|null. */
$lookup = function (array $raw) use ($repo): ?int {
    $ext = $raw['_id'] ?? null;
    $name = trim($raw['name'] ?? '');

    $d = $ext ? $repo->findOneByField('unique_id', $ext) : null;
    if (! $d && $name !== '') {
        $d = $repo->findOneByField('name', $name);
    }
    if (! $d && $name !== '') {
        foreach (array_filter(explode(' ', $name)) as $p) {
            if (strlen($p) <= 2) {
                continue;
            }
            $f = DB::table('doctors')->where('name', 'like', '%'.$p.'%')->where('is_active', 1)->first();
            if ($f) {
                return (int) $f->id;
            }
        }
    }

    return $d?->id;
};

$files = glob(__DIR__.'/Odontoking_*_eventos.json');

$stats = [
    'eventos'          => 0,
    'sin_cita_crm'     => 0,       // el evento no está en el CRM (no se creó)
    'ok'               => 0,       // doctores ya correctos
    'desajuste'        => 0,       // el CRM tiene distinto doctor
    'no_identificable' => 0,       // ni el export identifica médico
    'crearia_doctor'   => 0,       // el médico correcto no existe en doctors
];
$muestras = [];
$doctoresACrear = [];

foreach ($files as $file) {
    $events = json_decode(file_get_contents($file), true);
    foreach ($events as $ev) {
        $stats['eventos']++;
        $ext = $ev['_id'] ?? null;
        if (! $ext) {
            continue;
        }

        // ¿existe la cita en el CRM?
        $row = DB::table('smd_synced_events')->where('external_id', $ext)->first(['activity_id']);
        $activityId = $row->activity_id ?? null;
        if (! $activityId) {
            $stats['sin_cita_crm']++;

            continue;
        }

        $n = $mNorm->invoke($svc, $ev);
        if (! $n['doctor_identified']) {
            $stats['no_identificable']++;

            continue;
        }

        // ids correctos según el export
        $correctIds = [];
        $faltantes = [];
        foreach ($n['doctors'] as $dd) {
            $id = $lookup($dd);
            if ($id) {
                $correctIds[$id] = true;
            } else {
                $faltantes[] = $dd['name'];
            }
        }
        if ($faltantes) {
            $stats['crearia_doctor']++;
            foreach ($faltantes as $fn) {
                $doctoresACrear[$fn] = ($doctoresACrear[$fn] ?? 0) + 1;
            }
        }
        $correctIds = array_keys($correctIds);
        if (! $correctIds) {
            continue;
        }

        // ids actuales en el CRM
        $currentIds = DB::table('doctor_activities')->where('activity_id', $activityId)->pluck('doctor_id')->map(fn ($x) => (int) $x)->sort()->values()->all();
        sort($correctIds);

        if ($currentIds === $correctIds) {
            $stats['ok']++;
        } else {
            $stats['desajuste']++;
            if (count($muestras) < 15) {
                $curNames = DB::table('doctors')->whereIn('id', $currentIds)->pluck('name')->implode(', ');
                $newNames = DB::table('doctors')->whereIn('id', $correctIds)->pluck('name')->implode(', ');
                $muestras[] = "act $activityId  [".($curNames ?: '-')."]  =>  [$newNames]";
            }
        }
    }
}

echo "== RECONCILIACIÓN (dry-run) ==\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 20).$v."\n";
}
echo "\n-- muestras de desajuste (actual => correcto) --\n";
foreach ($muestras as $m) {
    echo '  '.$m."\n";
}
if ($doctoresACrear) {
    echo "\n-- doctores que faltarían crear --\n";
    arsort($doctoresACrear);
    foreach ($doctoresACrear as $k => $v) {
        echo '  '.str_pad($v, 3).$k."\n";
    }
}
