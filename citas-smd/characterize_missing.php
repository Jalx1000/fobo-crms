<?php

/**
 * FASE B (paso 1) — Caracteriza los eventos del export que NO existen como cita
 * en el CRM, para separar lo creable de la basura (test/cancelado/sin paciente).
 * Solo lectura.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$svc = app(\Webkul\Admin\Services\IncomingAppointmentService::class);
$mNorm = new ReflectionMethod($svc, 'normalizeDropbox');
$mNorm->setAccessible(true);

// última versión por external_id
$byExt = [];
foreach (glob(__DIR__.'/Odontoking_*_eventos.json') as $file) {
    foreach (json_decode(file_get_contents($file), true) as $ev) {
        $ext = $ev['_id'] ?? null;
        if (! $ext) {
            continue;
        }
        if (($ev['updated_at'] ?? '') >= ($byExt[$ext]['updated_at'] ?? '')) {
            $byExt[$ext] = $ev;
        }
    }
}

$c = [
    'total_export'    => count($byExt),
    'ya_en_crm'       => 0,
    'faltantes'       => 0,
    // desglose de faltantes:
    'archived'        => 0,
    'cancelado'       => 0,
    'sin_fecha'       => 0,
    'sin_paciente'    => 0,
    'sin_doctor'      => 0,
    'creable_limpio'  => 0,
];
$statusDist = [];
$muestraCreable = [];

foreach ($byExt as $ext => $ev) {
    if (DB::table('smd_synced_events')->where('external_id', $ext)->exists()) {
        $c['ya_en_crm']++;

        continue;
    }
    $c['faltantes']++;

    $st = strtoupper($ev['status'] ?? '');
    $statusDist[$st ?: '(vacío)'] = ($statusDist[$st ?: '(vacío)'] ?? 0) + 1;

    $archived = ! empty($ev['archived']);
    $n = $mNorm->invoke($svc, $ev);
    $hasDate = ! empty($ev['startDate']);
    $hasPatient = $n['patient_identified'];
    $hasDoctor = $n['doctor_identified'];

    if ($archived) {
        $c['archived']++;
    }
    if (in_array($st, ['CANCELED', 'CANCELLED'])) {
        $c['cancelado']++;
    }
    if (! $hasDate) {
        $c['sin_fecha']++;
    }
    if (! $hasPatient) {
        $c['sin_paciente']++;
    }
    if (! $hasDoctor) {
        $c['sin_doctor']++;
    }

    // "creable limpio" = tiene fecha, paciente y doctor, no archivado ni cancelado
    if ($hasDate && $hasPatient && $hasDoctor && ! $archived && ! in_array($st, ['CANCELED', 'CANCELLED'])) {
        $c['creable_limpio']++;
        if (count($muestraCreable) < 10) {
            $muestraCreable[] = substr($n['patient_name'], 0, 24).'  dr='.($n['doctors'][0]['name'] ?? '?').'  '.substr($ev['startDate'], 0, 10).'  st='.$st;
        }
    }
}

echo "== CARACTERIZACIÓN de faltantes ==\n";
foreach ($c as $k => $v) {
    echo '  '.str_pad($k, 16).$v."\n";
}
echo "\n-- distribución por status --\n";
arsort($statusDist);
foreach ($statusDist as $k => $v) {
    echo '  '.str_pad($k, 14).$v."\n";
}
echo "\n-- muestras creables --\n";
foreach ($muestraCreable as $m) {
    echo '  '.$m."\n";
}
