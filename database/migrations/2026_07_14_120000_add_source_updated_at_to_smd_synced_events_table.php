<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMD escribe un archivo por cada cambio de la cita y no borra los anteriores,
     * asi que la misma cita llega varias veces con distintas versiones. Guardar el
     * `updated_at` del payload permite descartar los archivos viejos.
     */
    public function up(): void
    {
        Schema::table('smd_synced_events', function (Blueprint $table) {
            $table->timestamp('source_updated_at')->nullable()->after('status');
        });

        // Backfill desde el payload ya guardado para que el descarte de obsoletos
        // tenga referencia desde la primera corrida.
        DB::table('smd_synced_events')
            ->select('id', 'raw_payload')
            ->whereNotNull('raw_payload')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $updatedAt = json_decode($row->raw_payload, true)['updated_at'] ?? null;

                    if (! $updatedAt) {
                        continue;
                    }

                    DB::table('smd_synced_events')
                        ->where('id', $row->id)
                        ->update(['source_updated_at' => Carbon::parse($updatedAt)->utc()->format('Y-m-d H:i:s')]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('smd_synced_events', function (Blueprint $table) {
            $table->dropColumn('source_updated_at');
        });
    }
};
