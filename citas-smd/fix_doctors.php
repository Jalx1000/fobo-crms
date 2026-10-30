<?php

/**
 * FASE A - Corrige la asignación de doctores de las citas ya existentes,
 * tomando el export mensual de SMD como fuente de verdad.
 *
 * Uso:
 *   php citas-smd/fix_doctors.php            (dry-run, no escribe)
 *   php citas-smd/fix_doctors.php apply      (aplica en una transacción)
 *
 * Solo toca el pivote doctor_activities. No crea doctores: si el médico
 * correcto no existe todavía, salta la cita y la reporta.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Webkul\Doctor\Repositories\DoctorRepository;

$apply = ($argv[1] ?? '') === 'apply';

$svc = app(\Webkul\Admin\Services\IncomingAppointmentService::class);
$mNorm = new ReflectionMethod($svc, 'normalizeDropbox');
$mNorm->setAccessible(true);
$repo = app(DoctorRepository::class);

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

// Última versión de cada evento por external_id (el export puede repetir).
$byExt = [];
foreach (glob(__DIR__.'/Odontoking_*_eventos.json') as $file) {
    foreach (json_decode(file_get_contents($file), true) as $ev) {
        $ext = $ev['_id'] ?? null;
        if (! $ext) {
            continue;
        }
        $prev = $byExt[$ext]['updated_at'] ?? '';
        if (($ev['updated_at'] ?? '') >= $prev) {
            $byExt[$ext] = $ev;
        }
    }
}

$stats = ['revisados' => 0, 'sin_cita' => 0, 'ok' => 0, 'no_ident' => 0, 'saltados_crear' => 0, 'corregidos' => 0];
$cambios = [];

$run = function () use (&$stats, &$cambios, $byExt, $mNorm, $svc, $lookup, $apply) {
    foreach ($byExt as $ext => $ev) {
        $stats['revisados']++;
        $aid = DB::table('smd_synced_events')->where('external_id', $ext)->value('activity_id');
        if (! $aid) {
            $stats['sin_cita']++;

            continue;
        }

        $n = $mNorm->invoke($svc, $ev);
        if (! $n['doctor_identified']) {
            $stats['no_ident']++;

            continue;
        }

        $correct = [];
        $missing = false;
        foreach ($n['doctors'] as $dd) {
            $id = $lookup($dd);
            if ($id) {
                $correct[$id] = true;
            } else {
                $missing = true;
            }
        }
        if ($missing || ! $correct) {
            $stats['saltados_crear']++;

            continue;
        }
        $correct = array_keys($correct);
        sort($correct);

        $cur = DB::table('doctor_activities')->where('activity_id', $aid)->pluck('doctor_id')->map(fn ($x) => (int) $x)->sort()->values()->all();
        if ($cur === $correct) {
            $stats['ok']++;

            continue;
        }

        $stats['corregidos']++;
        if (count($cambios) < 20) {
            $cn = DB::table('doctors')->whereIn('id', $cur)->pluck('name')->implode(', ');
            $nn = DB::table('doctors')->whereIn('id', $correct)->pluck('name')->implode(', ');
            $cambios[] = "act $aid  [".($cn ?: '-')."] => [$nn]";
        }

        if ($apply) {
            DB::table('doctor_activities')->where('activity_id', $aid)->delete();
            foreach ($correct as $did) {
                DB::table('doctor_activities')->insert(['activity_id' => $aid, 'doctor_id' => $did]);
            }
        }
    }
};

if ($apply) {
    DB::transaction($run);
} else {
    $run();
}

echo ($apply ? '== APLICADO ==' : '== DRY-RUN ==')."\n";
foreach ($stats as $k => $v) {
    echo '  '.str_pad($k, 16).$v."\n";
}
echo "\n-- muestras --\n";
foreach ($cambios as $c) {
    echo '  '.$c."\n";
}
