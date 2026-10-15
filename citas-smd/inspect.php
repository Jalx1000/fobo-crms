<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$targets = [28526, 28886, 27966, 29673, 46654];

// index export por external_id -> evento
$byExt = [];
foreach (glob(__DIR__.'/Odontoking_*_eventos.json') as $file) {
    foreach (json_decode(file_get_contents($file), true) as $ev) {
        if (! empty($ev['_id'])) {
            $byExt[$ev['_id']] = $ev;
        }
    }
}

foreach ($targets as $aid) {
    echo str_repeat('=', 70)."\n";
    $act = DB::table('activities')->where('id', $aid)->first(['id', 'title', 'schedule_from', 'additional', 'updated_at']);
    if (! $act) {
        echo "act $aid no existe\n";

        continue;
    }
    $ext = json_decode($act->additional, true)['external_id'] ?? DB::table('smd_synced_events')->where('activity_id', $aid)->value('external_id');
    $curDocs = DB::table('doctor_activities as da')->join('doctors as d', 'd.id', '=', 'da.doctor_id')->where('da.activity_id', $aid)->pluck('d.name')->implode(', ');

    echo "ACT $aid  sched={$act->schedule_from}  updated={$act->updated_at}\n";
    echo "  ext=$ext\n";
    echo '  CRM doctor(s): '.($curDocs ?: '-')."\n";

    $ev = $byExt[$ext] ?? null;
    if (! $ev) {
        echo "  >> sin evento en export\n";

        continue;
    }
    echo '  export updated_at: '.($ev['updated_at'] ?? '?').'  status='.($ev['status'] ?? '').' archived='.json_encode($ev['archived'] ?? null)."\n";
    $o = $ev['owner'] ?? [];
    echo '  owner: '.($o['name'] ?? '').' '.($o['lastName'] ?? '').' type='.($o['type'] ?? '-')."\n";
    echo '  attendances ('.count($ev['attendances'] ?? [])."):\n";
    foreach (($ev['attendances'] ?? []) as $a) {
        $reg = DB::table('doctors')->where('unique_id', $a['_id'] ?? '')->value('name');
        echo '     '.substr(($a['name'] ?? '').' '.($a['lastName'] ?? ''), 0, 34).'  type='.($a['type'] ?? '-').'  reg='.($reg ?: 'no')."\n";
    }
}
