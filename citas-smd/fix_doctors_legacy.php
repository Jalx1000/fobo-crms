<?php

/**
 * FASE A.2 — Corrige los doctores de las citas placeholder/recurso que quedan
 * FUERA de la ventana del export (jul/ago). Fuente: smd_synced_events.raw_payload
 * (los payloads de Dropbox que el CRM ya tenía persistidos).
 *
 * Para no pisar lo que el export ya definió como verdad, SALTA cualquier cita
 * cuyo external_id esté presente en los archivos del export.
 *
 * Uso: php citas-smd/fix_doctors_legacy.php [apply]
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

// external_ids que el export ya cubrió (no tocarlos aquí).
$exportExt = [];
foreach (glob(__DIR__.'/Odontoking_*_eventos.json') as $file) {
    foreach (json_decode(file_get_contents($file), true) as $ev) {
        if (! empty($ev['_id'])) {
            $exportExt[$ev['_id']] = true;
        }
    }
}

$affected = DB::table('doctor_activities')->whereIn('doctor_id', [2021, 1177, 458])->pluck('activity_id')->unique()->values();

$stats = ['afectadas' => $affected->count(), 'en_export' => 0, 'sin_payload' => 0, 'no_ident' => 0, 'saltados_crear' => 0, 'corregidos' => 0, 'ya_ok' => 0];
$cambios = [];

$run = function () use (&$stats, &$cambios, $affected, $exportExt, $mNorm, $svc, $lookup, $apply) {
    foreach ($affected as $aid) {
        $ev = DB::table('smd_synced_events')->where('activity_id', $aid)->orderByDesc('source_updated_at')->first(['external_id', 'raw_payload']);
        if ($ev && isset($exportExt[$ev->external_id])) {
            $stats['en_export']++;

            continue;
        }
        if (! $ev || ! $ev->raw_payload) {
            $stats['sin_payload']++;

            continue;
        }

        $n = $mNorm->invoke($svc, json_decode($ev->raw_payload, true));
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
            $stats['ya_ok']++;

            continue;
        }

        $stats['corregidos']++;
        if (count($cambios) < 20) {
            $cn = DB::table('doctors')->whereIn('id', $cur)->pluck('name')->implode(', ');
            $nn = DB::table('doctors')->whereIn('id', $correct)->pluck('name')->implode(', ');
            $cambios[] = "act $aid  [".($cn ?: '—')."] => [$nn]";
        }

        if ($apply) {
            DB::table('doctor_activities')->where('activity_id', $aid)->delete();
            foreach ($correct as $did) {
                DB::table('doctor_activities')->insert(['activity_id' => $aid, 'doctor_id' => $did]);
            }
        }
    }
};

$apply ? DB::transaction($run) : $run();

echo ($apply ? '== APLICADO ==' : '== DRY-RUN ==')."\n";
foreach ($stats as $k => $v) {
    echo '  '.str_pad($k, 16).$v."\n";
}
echo "\n-- muestras --\n";
foreach ($cambios as $c) {
    echo '  '.$c."\n";
}
