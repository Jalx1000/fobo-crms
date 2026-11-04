<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every file attached to a contact or a deal, from wherever it arrived.
 *
 * Three sources feed the same tab, each with its own storage shape:
 *
 *   - activity_files    a file activity uploaded by an advisor (path on disk)
 *   - whatsapp_messages media sent or received in a chat (media_path is
 *                       already a public URL, unlike the other two)
 *   - email_attachments an attachment on a synced email
 *
 * A person aggregates the full history: their own activities plus everything
 * belonging to ANY of their leads. A lead only shows its own - the complete
 * picture lives on the contact, which is where you go to answer "what have we
 * ever exchanged with them?".
 */
class EntityFiles
{
    /**
     * Extensions we treat as viewable images/videos. Anything else that is not
     * one of these is a document - a permissive default, because an unknown
     * file the advisor uploaded on purpose should still be listed and
     * downloadable rather than dropped.
     */
    protected const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic', 'avif'];

    protected const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'm4v', 'mpeg', 'mpg'];

    /**
     * Full file history of a contact: their own activities, plus every file of
     * every lead they own.
     */
    public function forPerson(int $personId): array
    {
        $leadIds = DB::table('leads')->where('person_id', $personId)->pluck('id')->all();

        return $this->merge(
            $this->activityFiles(personId: $personId, leadIds: $leadIds),
            $this->whatsappFiles(personId: $personId, leadIds: $leadIds),
            $this->emailFiles(personId: $personId, leadIds: $leadIds),
        );
    }

    /**
     * Files of a single deal.
     */
    public function forLead(int $leadId): array
    {
        return $this->merge(
            $this->activityFiles(leadIds: [$leadId]),
            $this->whatsappFiles(leadIds: [$leadId]),
            $this->emailFiles(leadIds: [$leadId]),
        );
    }

    /**
     * Files uploaded as "file" activities, reached through either pivot.
     */
    protected function activityFiles(?int $personId = null, array $leadIds = []): array
    {
        // Without this an empty where() would match the whole table.
        if (! $personId && ! $leadIds) {
            return [];
        }

        return DB::table('activity_files')
            ->join('activities', 'activities.id', '=', 'activity_files.activity_id')
            ->leftJoin('users', 'users.id', '=', 'activities.user_id')
            ->where(function ($where) use ($personId, $leadIds) {
                if ($personId) {
                    $where->orWhereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('person_activities')
                        ->whereColumn('person_activities.activity_id', 'activities.id')
                        ->where('person_activities.person_id', $personId));
                }

                if ($leadIds) {
                    $where->orWhereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('lead_activities')
                        ->whereColumn('lead_activities.activity_id', 'activities.id')
                        ->whereIn('lead_activities.lead_id', $leadIds));
                }
            })
            ->orderByDesc('activity_files.id')
            ->get([
                'activity_files.id',
                'activity_files.name',
                'activity_files.path',
                'activity_files.created_at',
                'users.name as author',
            ])
            ->map(fn ($row) => $this->item(
                id: 'activity-'.$row->id,
                name: $row->name,
                url: Storage::url($row->path),
                source: 'activity',
                date: $row->created_at,
                author: $row->author,
            ))
            ->all();
    }

    /**
     * WhatsApp media. media_path already holds a public URL (the inbox renders
     * it straight into an <img>), so it is passed through untouched.
     */
    protected function whatsappFiles(?int $personId = null, array $leadIds = []): array
    {
        // The Whatsapp module is optional; this service ships with Admin and
        // must not blow up on an install that does not have it.
        if ((! $personId && ! $leadIds) || ! Schema::hasTable('whatsapp_messages')) {
            return [];
        }

        $conversationIds = DB::table('whatsapp_conversations')
            ->where(function ($where) use ($personId, $leadIds) {
                if ($personId) {
                    $where->orWhere('person_id', $personId);
                }

                if ($leadIds) {
                    $where->orWhereIn('lead_id', $leadIds);
                }
            })
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return [];
        }

        return DB::table('whatsapp_messages')
            ->whereIn('conversation_id', $conversationIds)
            ->whereNotNull('media_path')
            // Stickers are chat reactions, not documents anybody files away.
            ->whereIn('type', ['image', 'video', 'document', 'audio'])
            ->orderByDesc('id')
            ->get(['id', 'type', 'body', 'media_path', 'media_mime', 'media_name', 'direction', 'created_at'])
            ->map(fn ($row) => $this->item(
                id: 'whatsapp-'.$row->id,
                // Media without a filename (a photo straight from the camera)
                // falls back to the caption, then to a generic label.
                name: $row->media_name ?: (Str::limit((string) $row->body, 40) ?: $this->labelForType($row->type)),
                url: $row->media_path,
                source: 'whatsapp',
                date: $row->created_at,
                mime: $row->media_mime,
                author: $row->direction === 'inbound' ? 'Cliente' : 'Equipo',
                kind: $this->kindFromWhatsappType($row->type, $row->media_mime, $row->media_name),
            ))
            ->all();
    }

    /**
     * Attachments on synced emails.
     */
    protected function emailFiles(?int $personId = null, array $leadIds = []): array
    {
        if ((! $personId && ! $leadIds) || ! Schema::hasTable('email_attachments')) {
            return [];
        }

        return DB::table('email_attachments')
            ->join('emails', 'emails.id', '=', 'email_attachments.email_id')
            ->where(function ($where) use ($personId, $leadIds) {
                if ($personId) {
                    $where->orWhere('emails.person_id', $personId);
                }

                if ($leadIds) {
                    $where->orWhereIn('emails.lead_id', $leadIds);
                }
            })
            ->orderByDesc('email_attachments.id')
            ->get([
                'email_attachments.id',
                'email_attachments.name',
                'email_attachments.path',
                'email_attachments.size',
                'email_attachments.content_type',
                'email_attachments.created_at',
                'emails.subject',
            ])
            ->map(fn ($row) => $this->item(
                id: 'email-'.$row->id,
                name: $row->name ?: 'Adjunto',
                url: Storage::url($row->path),
                source: 'email',
                date: $row->created_at,
                mime: $row->content_type,
                size: $row->size,
                author: $row->subject,
            ))
            ->all();
    }

    /**
     * Normalize one file, whatever it came from.
     */
    protected function item(
        string $id,
        ?string $name,
        string $url,
        string $source,
        $date,
        ?string $mime = null,
        ?int $size = null,
        ?string $author = null,
        ?string $kind = null,
    ): array {
        $name = $name ?: 'Archivo';

        return [
            'id'           => $id,
            'kind'         => $kind ?? $this->kindFor($name, $mime),
            'name'         => $name,
            'url'          => $url,
            'mime'         => $mime,
            'size'         => $size,
            'source'       => $source,
            'source_label' => [
                'whatsapp' => 'WhatsApp',
                'activity' => 'Actividad',
                'email'    => 'Email',
            ][$source] ?? $source,
            'author'     => $author,
            'date'       => $date ? Carbon::parse($date)->toIso8601String() : null,
            'date_human' => $date ? Carbon::parse($date)->format('d/m/Y H:i') : null,
        ];
    }

    /**
     * WhatsApp already tells us the type; only 'document' needs a second look,
     * since a PDF and a photo sent as a file both arrive as 'document'.
     */
    protected function kindFromWhatsappType(string $type, ?string $mime, ?string $name): string
    {
        return match ($type) {
            'image'    => 'image',
            'video'    => 'video',
            'audio'    => 'audio',
            default    => $this->kindFor((string) $name, $mime),
        };
    }

    /**
     * Classify by mime first (authoritative when present), then by extension.
     */
    protected function kindFor(string $name, ?string $mime): string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }

        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }

        return 'document';
    }

    protected function labelForType(string $type): string
    {
        return [
            'image'    => 'Imagen',
            'video'    => 'Video',
            'audio'    => 'Audio',
            'document' => 'Documento',
        ][$type] ?? 'Archivo';
    }

    /**
     * Newest first across every source, so the tab reads as one timeline.
     */
    protected function merge(array ...$groups): array
    {
        $files = array_merge(...$groups);

        usort($files, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $files;
    }
}
