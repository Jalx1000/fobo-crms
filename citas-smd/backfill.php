<?php

/**
 * FASE B (paso 2) — Backfill de las citas del export que faltan en el CRM.
 *
 * Reutiliza SmdAppointmentSyncService::processPayload (el mismo entry point del
 * cron/webhook): idempotente, registra en smd_synced_events, crea las citas
 * reales, salta bloqueos ("Ocupado", sin paciente) y cancelados.
 *
 * Uso:
 *   php citas-smd/backfill.php 5      -> procesa solo 5 (prueba)
 *   php citas-smd/backfill.php        -> procesa todos los faltantes
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$limit = isset($argv[1]) ? (int) $argv[1] : PHP_INT_MAX;

$svc = app(\Webkul\Admin\Services\SmdAppointmentSyncService::class);

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

$summary = [];
$done = 0;

foreach ($byExt as $ext => $ev) {
    if ($done >= $limit) {
        break;
    }
    // Solo faltantes: si ya está en smd_synced_events, no lo tocamos.
    if (DB::table('smd_synced_events')->where('external_id', $ext)->exists()) {
        continue;
    }

    $key = $svc->processPayload($ev, 'backfill-export');
    $summary[$key] = ($summary[$key] ?? 0) + 1;
    $done++;
}

echo "== BACKFILL (procesados: $done) ==\n";
arsort($summary);
foreach ($summary as $k => $v) {
    echo '  '.str_pad($k, 14).$v."\n";
}
