<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Folds the standalone Messenger store into the shared inbox.
 *
 * The first cut of this module kept its own conversations and messages and
 * *mirrored* them into whatsapp_conversations/whatsapp_messages. Two copies of
 * the same thread drift the moment either side is written alone - which is what
 * happened: replies sent from one inbox never appeared in the other.
 *
 * After this migration there is one store. Messenger is a channel
 * (gateway = 'messenger'), keyed by PSID in provider_conversation_id, and the
 * per-page credentials in `social_connections` are all that remains of the
 * separate schema.
 *
 * Every step is guarded so the migration is safe to re-run and safe on a fresh
 * install where the old tables never existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->carryOverConversations();
        $this->carryOverMessages();
        $this->carryOverAssignments();
        $this->clearMirroredPsidPhones();
        $this->rewriteProxiedAvatarUrls();
        $this->dropRedundantTables();
        $this->dropUnusedBrandColumns();
    }

    /**
     * Irreversible on purpose: the two stores are merged, and there is no
     * honest way to split a thread back out once replies from both inboxes
     * live in one timeline. Restore from a backup instead.
     */
    public function down(): void
    {
        // no-op
    }

    /**
     * Make sure every Messenger thread exists in the shared store, with the
     * page it belongs to attached - the mirror never recorded that.
     */
    protected function carryOverConversations(): void
    {
        if (! Schema::hasTable('messenger_conversations')) {
            return;
        }

        DB::table('messenger_conversations')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $pageId = DB::table('social_connections')
                        ->where('id', $row->connection_id)
                        ->value('account_id');

                    $existing = DB::table('whatsapp_conversations')
                        ->where('gateway', 'messenger')
                        ->where('provider_conversation_id', $row->psid)
                        ->first();

                    if ($existing) {
                        DB::table('whatsapp_conversations')
                            ->where('id', $existing->id)
                            ->update(array_filter([
                                'provider_account_id' => $existing->provider_account_id ?: $pageId,
                                'wa_name'             => $existing->wa_name ?: $row->peer_name,
                                'last_message_at'     => $existing->last_message_at ?: $row->last_message_at,
                            ], fn ($value) => $value !== null));

                        continue;
                    }

                    DB::table('whatsapp_conversations')->insert([
                        'gateway'                  => 'messenger',
                        'wa_phone'                 => null, // a PSID is not a phone
                        'provider_conversation_id' => $row->psid,
                        'provider_account_id'      => $pageId,
                        'wa_name'                  => $row->peer_name,
                        'status'                   => 'unassigned',
                        'ai_enabled'               => 0,
                        'last_message_at'          => $row->last_message_at,
                        'created_at'               => $row->created_at,
                        'updated_at'               => $row->updated_at,
                    ]);
                }
            });
    }

    /**
     * Copy any message the mirror missed - outbound replies sent from the old
     * social-inbox endpoint were never mirrored at all.
     */
    protected function carryOverMessages(): void
    {
        if (! Schema::hasTable('messenger_messages')) {
            return;
        }

        DB::table('messenger_messages')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $psid = DB::table('messenger_conversations')
                        ->where('id', $row->conversation_id)
                        ->value('psid');

                    if (! $psid) {
                        continue;
                    }

                    $conversationId = DB::table('whatsapp_conversations')
                        ->where('gateway', 'messenger')
                        ->where('provider_conversation_id', $psid)
                        ->value('id');

                    if (! $conversationId) {
                        continue;
                    }

                    // wa_message_id is unique and carries the provider's mid,
                    // which is exactly what the mirror wrote - so this is the
                    // same idempotency key both halves already used.
                    $alreadyThere = $row->external_id
                        ? DB::table('whatsapp_messages')->where('wa_message_id', $row->external_id)->exists()
                        : DB::table('whatsapp_messages')
                            ->where('conversation_id', $conversationId)
                            ->where('created_at', $row->created_at)
                            ->where('body', $row->content)
                            ->exists();

                    if ($alreadyThere) {
                        continue;
                    }

                    DB::table('whatsapp_messages')->insert([
                        'conversation_id' => $conversationId,
                        'direction'       => $row->direction === 'out' ? 'outbound' : 'inbound',
                        'type'            => $row->message_type,
                        'body'            => $row->content,
                        'media_path'      => $row->media_url,
                        'wa_message_id'   => $row->external_id,
                        'status'          => $row->status,
                        'sender'          => $row->direction === 'out' ? 'human' : 'contact',
                        'payload'         => $row->payload,
                        'created_at'      => $row->created_at,
                        'updated_at'      => $row->updated_at,
                    ]);
                }
            });
    }

    /**
     * The parallel inbox had its own assignment table. An assignment is the
     * shared inbox's "assigned" handoff state, so it lands there.
     */
    protected function carryOverAssignments(): void
    {
        if (! Schema::hasTable('conversation_assignments') || ! Schema::hasTable('messenger_conversations')) {
            return;
        }

        $assignments = DB::table('conversation_assignments')
            ->where('channel', 'messenger')
            ->whereNotNull('assigned_user_id')
            ->get();

        foreach ($assignments as $assignment) {
            $psid = DB::table('messenger_conversations')
                ->where('id', $assignment->conversation_id)
                ->value('psid');

            if (! $psid) {
                continue;
            }

            DB::table('whatsapp_conversations')
                ->where('gateway', 'messenger')
                ->where('provider_conversation_id', $psid)
                ->whereNull('assigned_user_id')
                ->update([
                    'assigned_user_id' => $assignment->assigned_user_id,
                    'assigned_at'      => $assignment->updated_at,
                    'handoff_state'    => 'assigned',
                    'handoff_source'   => 'human',
                ]);
        }
    }

    /**
     * The mirror wrote the PSID into wa_phone. That single line is what let a
     * Messenger thread collide with a real phone number in the inbox list and
     * disappear behind it.
     */
    protected function clearMirroredPsidPhones(): void
    {
        DB::table('whatsapp_conversations')
            ->where('gateway', 'messenger')
            ->whereNotNull('wa_phone')
            ->update(['wa_phone' => null]);
    }

    /**
     * Avatars used to be served through an API proxy backed by social_media_files.
     * The files themselves are already on the public disk, so the stored rows
     * are turned into direct public URLs before the table goes away.
     */
    protected function rewriteProxiedAvatarUrls(): void
    {
        if (! Schema::hasTable('social_media_files') || ! Schema::hasTable('social_contacts')) {
            return;
        }

        $contacts = DB::table('social_contacts')->whereNotNull('avatar_url')->get();

        foreach ($contacts as $contact) {
            if (! preg_match('#/api/v1/media-file/(\d+)$#', (string) $contact->avatar_url, $matches)) {
                continue;
            }

            $file = DB::table('social_media_files')->where('id', (int) $matches[1])->first();

            if (! $file) {
                continue;
            }

            $url = Storage::disk($file->disk ?: 'public')->url($file->path);

            // The identity table is the only link from a contact to its PSID.
            $psids = Schema::hasTable('social_contact_identities')
                ? DB::table('social_contact_identities')
                    ->where('contact_id', $contact->id)
                    ->where('channel', 'messenger')
                    ->pluck('external_id')
                : collect();

            if ($psids->isEmpty()) {
                continue;
            }

            DB::table('whatsapp_conversations')
                ->where('gateway', 'messenger')
                ->whereIn('provider_conversation_id', $psids)
                ->whereNull('avatar_url')
                ->update(['avatar_url' => $url]);
        }
    }

    /**
     * Everything the shared store now covers. Dropped in FK order.
     */
    protected function dropRedundantTables(): void
    {
        foreach ([
            'messenger_messages',
            'messenger_conversations',
            'conversation_assignments',
            'social_contact_identities',
            'social_contacts',
            'social_media_files',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * `brand_id` came from a multi-tenant product this CRM is not. It was also
     * read straight off a client-supplied header, so it never authorised
     * anything - it only looked like it did.
     */
    protected function dropUnusedBrandColumns(): void
    {
        if (! Schema::hasTable('social_connections') || ! Schema::hasColumn('social_connections', 'brand_id')) {
            return;
        }

        foreach ($this->indexesCovering('social_connections', 'brand_id') as $index) {
            Schema::table('social_connections', fn (Blueprint $table) => $table->dropIndex($index));
        }

        Schema::table('social_connections', fn (Blueprint $table) => $table->dropColumn('brand_id'));
    }

    /**
     * Names of the indexes covering a column. Laravel 10 has no
     * Schema::hasIndex(), and SHOW INDEX avoids information_schema, which the
     * app's DB user may not be allowed to read.
     *
     * @return array<int, string>
     */
    protected function indexesCovering(string $table, string $column): array
    {
        $names = [];

        foreach (DB::select('SHOW INDEX FROM `'.DB::getTablePrefix().$table.'`') as $row) {
            if ($row->Column_name === $column) {
                $names[$row->Key_name] = $row->Key_name;
            }
        }

        return array_values($names);
    }
};
