<?php
// Diagnóstico: ¿coinciden doctors.unique_id (local) con physician._id (SMD)?
// Uso:  php artisan tinker --execute="require '/ruta/smd_id_diag.php';"
// (o pega el contenido en `php artisan tinker`)

use Webkul\Admin\Services\ShareMeDataService;

$smd = app(ShareMeDataService::class);

// 1) IDs locales
$locals = \DB::table('doctors')
    ->whereNotNull('unique_id')->where('unique_id', '!=', '')
    ->get(['id', 'name', 'unique_id', 'email']);

echo "== Doctores locales con unique_id: " . $locals->count() . " ==\n";

// 2) Recolectar TODOS los physician._id reales de SMD en una ventana amplia
$from = now()->startOfDay()->format('Y-m-d H:i:s');
$to   = now()->addDays(14)->endOfDay()->format('Y-m-d H:i:s');

$specialties = $smd->getSpecialties();
if (empty($specialties)) {
    $specialties = \DB::table('specialties')->pluck('name')->toArray();
}

$smdPhysicians = []; // _id => "Nombre Apellido"
foreach ($specialties as $spec) {
    $smd->checkAvailability(null, $spec, 'Santa Cruz', $from, $to); // null => solo queremos el raw
    $raw = $smd->getLastResponse();
    if ($raw && isset($raw['body']) && is_array($raw['body'])) {
        foreach ($raw['body'] as $item) {
            $pid = $item['physician']['_id'] ?? null;
            if ($pid) {
                $smdPhysicians[$pid] = trim(($item['physician']['name'] ?? '') . ' ' . ($item['physician']['lastName'] ?? ''));
            }
        }
    }
}

echo "== Physicians distintos devueltos por SMD: " . count($smdPhysicians) . " ==\n\n";

// 3) Comparar
$matched = 0; $unmatched = 0;
foreach ($locals as $d) {
    $hit = array_key_exists($d->unique_id, $smdPhysicians);
    $hit ? $matched++ : $unmatched++;
    printf("[%s] local#%d  %-28s  uid=%s%s\n",
        $hit ? 'OK ' : 'XX ',
        $d->id,
        mb_substr($d->name, 0, 28),
        $d->unique_id,
        $hit ? '  -> SMD: ' . $smdPhysicians[$d->unique_id] : '  -> NO existe como physician._id en SMD'
    );
}

echo "\nRESUMEN: coinciden=$matched  NO_coinciden=$unmatched\n";
echo "\n-- physician._id que SMD expone (para cotejar manualmente) --\n";
foreach ($smdPhysicians as $pid => $name) {
    echo "  $pid  =>  $name\n";
}
