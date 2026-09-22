<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Handoff: la conversación deja de ser del agente IA y pasa a ser
 * responsabilidad de un asesor con nombre (o del equipo, si no hay ninguno para
 * esa ciudad).
 *
 * Strings y no enums: cambiar un enum después necesita doctrine/dbal, y el
 * conjunto de estados se valida en PHP igual (ver Conversation::HANDOFF_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_state')) {
                // null = nunca se derivó. requested | assigned | resolved
                $table->string('handoff_state', 20)->nullable()->after('ai_enabled');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_reason')) {
                // Lo escribe un LLM; se trunca a 490 antes de guardar.
                $table->string('handoff_reason', 500)->nullable()->after('handoff_state');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_source')) {
                $table->string('handoff_source', 10)->nullable()->after('handoff_reason'); // ai | human
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_requested_at')) {
                $table->timestamp('handoff_requested_at')->nullable()->after('handoff_source');
            }

            /*
             * La ciudad con la que se ruteó, congelada en el momento del
             * handoff. Se guarda además de poder recalcularla porque explica
             * POR QUÉ le tocó a ese asesor: si mañana el cliente cambia de
             * ciudad, la derivación vieja sigue siendo legible.
             */
            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_city_id')) {
                $table->unsignedInteger('handoff_city_id')->nullable()->after('handoff_requested_at');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'assigned_user_id')) {
                // unsignedInteger, NO bigInteger: users.id es increments().
                // El mismatch falla con un errno 150 críptico.
                $table->unsignedInteger('assigned_user_id')->nullable()->after('handoff_city_id');
                $table->foreign('assigned_user_id')->references('id')->on('users')->onDelete('set null');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_user_id');
            }
        });

        // Sirve los filtros del inbox: "derivadas", "sin asesor" y "míos".
        if (! $this->hasIndex('wa_conv_handoff_idx')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->index(['handoff_state', 'assigned_user_id'], 'wa_conv_handoff_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if ($this->hasIndex('wa_conv_handoff_idx')) {
                $table->dropIndex('wa_conv_handoff_idx');
            }

            // La foreign key va antes que la columna que restringe.
            if (Schema::hasColumn('whatsapp_conversations', 'assigned_user_id')) {
                $table->dropForeign(['assigned_user_id']);
            }

            $table->dropColumn([
                'handoff_state',
                'handoff_reason',
                'handoff_source',
                'handoff_requested_at',
                'handoff_city_id',
                'assigned_user_id',
                'assigned_at',
            ]);
        });
    }

    /**
     * SHOW INDEX en vez del schema manager: introspeccionar con Doctrine
     * traería doctrine/dbal, la dependencia que esta migración evita.
     */
    protected function hasIndex(string $name): bool
    {
        $table = DB::getTablePrefix().'whatsapp_conversations';

        return (bool) DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]);
    }
};
