<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original schema assumed "one conversation per phone number", which only
 * holds for Cloud API. Providers that key by their own entity break it:
 *
 * - Kommo addresses by lead id, and a contact may have several leads, so the
 *   same phone legitimately appears on more than one conversation.
 * - Kommo's webhook carries no phone; it needs a follow-up API call that can
 *   fail, so the phone must be allowed to be absent.
 *
 * Uniqueness moves to (gateway, provider_conversation_id): one conversation per
 * provider entity. Cloud API rows leave provider_conversation_id NULL, and MySQL
 * permits many NULLs in a unique index, so they are unaffected.
 *
 * The new indexes get short explicit names: the auto-generated composite name
 * came to 65 chars once the table prefix is applied — one over MySQL's limit.
 *
 * Every step is guarded: MySQL commits each DDL statement implicitly, so a
 * failure half-way leaves earlier steps applied while the migration stays
 * unrecorded. This must be safe to re-run.
 */
return new class extends Migration
{
    protected string $table = 'whatsapp_conversations';

    /** Short explicit names — used verbatim, no table prefix applied. */
    protected string $phoneIndex = 'wa_conv_phone_idx';

    protected string $providerUnique = 'wa_conv_gateway_provider_uniq';

    public function up(): void
    {
        // Drop the original unique. The array form lets Laravel rebuild the
        // prefixed name it generated when the index was created.
        if ($this->hasIndex($this->generatedName('wa_phone', 'unique'))) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique(['wa_phone']);
            });
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->string('wa_phone')->nullable()->change();
        });

        // Checked by column, not by name: an earlier run of this migration may
        // have already created one under Laravel's generated name.
        if (! $this->hasIndexOnColumn('wa_phone')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->index('wa_phone', $this->phoneIndex);
            });
        }

        if (! $this->hasIndex($this->providerUnique)) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->unique(['gateway', 'provider_conversation_id'], $this->providerUnique);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex($this->providerUnique)) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique($this->providerUnique);
            });
        }

        if ($this->hasIndex($this->phoneIndex)) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropIndex($this->phoneIndex);
            });
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->string('wa_phone')->nullable(false)->change();
        });

        if (! $this->hasIndex($this->generatedName('wa_phone', 'unique'))) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->unique(['wa_phone']);
            });
        }
    }

    /**
     * Rebuild the index name Laravel generates for a column, including the
     * table prefix.
     */
    protected function generatedName(string $column, string $type): string
    {
        return strtolower(DB::getTablePrefix().$this->table.'_'.$column.'_'.$type);
    }

    /**
     * Laravel 10 has no Schema::hasIndex().
     *
     * SHOW INDEX rather than information_schema: the query builder would prefix
     * the catalog table name, and the app's DB user has no rights there anyway.
     */
    protected function indexes(): array
    {
        return DB::select('SHOW INDEX FROM `'.DB::getTablePrefix().$this->table.'`');
    }

    protected function hasIndex(string $index): bool
    {
        foreach ($this->indexes() as $row) {
            if ($row->Key_name === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any index covering a column, whatever it happens to be called.
     */
    protected function hasIndexOnColumn(string $column): bool
    {
        foreach ($this->indexes() as $row) {
            if ($row->Column_name === $column) {
                return true;
            }
        }

        return false;
    }
};
