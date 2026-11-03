<?php
/*
 * Elimina los 73 contactos que dejaron los tests en septiembre 2026 (antes de
 * que usaran DatabaseTransactions). Sin teléfono, sin leads, sin actividades,
 * sin conversaciones. Decisión del usuario, 31/10/2026. Respaldo completo.
 */
use Illuminate\Support\Facades\DB;
$ps = DB::table('persons')->where('created_at', '>=', '2026-09-01')->where('created_at', '<', '2026-10-01')
  ->where(fn ($q) => $q->whereNull('contact_numbers')->orWhereRaw('json_length(contact_numbers) = 0'))
  ->whereRaw("(name regexp '^(Export City Prospect|Recent Prospect|City [0-9]+ Prospect|No City Prospect|Explicit Sin Ciudad|Unfiltered Prospect) [0-9]{4}$' or name = 'Test Person')")
  ->get();
if ($ps->isEmpty()) exit("Ya aplicado: no se hace nada.\n");
$ids = $ps->pluck('id');
foreach (['leads' => 'person_id', 'person_activities' => 'person_id', 'person_tags' => 'person_id', 'whatsapp_conversations' => 'person_id', 'quotes' => 'person_id', 'emails' => 'person_id'] as $t => $c)
  if (DB::table($t)->whereIn($c, $ids)->exists()) exit("No se aplica: hay referencias en {$t}.\n");
if ($ps->count() !== 73) exit("No se aplica: se esperaban 73 y hay {$ps->count()}.\n");
file_put_contents('/code/docs/eliminacion_contactos_test_septiembre_respaldo.json', json_encode([
  'persons' => $ps, 'attribute_values' => DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', $ids)->get(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
DB::transaction(function () use ($ids) {
  DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', $ids)->delete();
  DB::table('persons')->whereIn('id', $ids)->delete();
});
echo "Eliminados: ".$ids->count()." | quedan: ".DB::table('persons')->whereIn('id', $ids)->count()." | atributos sueltos: ".DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', $ids)->count()."\n";
echo "Contactos de septiembre sin teléfono que quedan: ".DB::table('persons')->where('created_at', '>=', '2026-09-01')->where('created_at', '<', '2026-10-01')->where(fn ($q) => $q->whereNull('contact_numbers')->orWhereRaw('json_length(contact_numbers) = 0'))->count()."\n";
