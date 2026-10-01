<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Brings an inbound attachment into the CRM.
 *
 * WhatsApp does not put the file in the webhook, only an id. That id resolves
 * to a URL that EXPIRES, so the download cannot wait for someone to open the
 * conversation: by then the customer's receipt is gone for good. Hence a job
 * that runs right after ingestion.
 *
 * A failure here never breaks the message: the text and the timeline entry are
 * already saved, and what is lost is the file, not the conversation.
 */
class DownloadInboundMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The reference expires, so retries are close together and few.
     */
    public $tries = 3;

    public $backoff = [5, 20];

    public function __construct(public int $messageId) {}

    public function handle(GatewayManager $gateways): void
    {
        $message = MessageProxy::modelClass()::with('conversation')->find($this->messageId);

        // Already downloaded (a retry that partly succeeded) or gone.
        if (! $message || $message->media_path) {
            return;
        }

        $gateway = $gateways->for($message->conversation);

        $reference = $gateway->mediaReference((array) $message->payload);

        if (! $reference) {
            return;
        }

        try {
            $file = $gateway->fetchMedia($reference['id']);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if (! $file || $file['contents'] === '') {
            Log::warning('WhatsApp: no se pudo descargar la media entrante', [
                'message_id' => $message->id,
                'media_id'   => $reference['id'],
            ]);

            return;
        }

        $mime = $this->cleanMime($file['mime'] ?? $reference['mime']);

        $name = $reference['filename'] ?: $this->defaultName($message->type, $mime);

        $path = 'whatsapp/inbound/'.Str::random(40).'.'.$this->extension($mime, $name);

        Storage::disk('public')->put($path, $file['contents']);

        $message->forceFill([
            'media_path' => route('whatsapp.media', ['file' => basename($path)]),
            'media_mime' => $mime,
            'media_name' => $name,
        ])->save();
    }

    /**
     * Meta sends things like `audio/ogg; codecs=opus`. The parameters belong in
     * the header, not in a column that later gets compared and used to pick an
     * extension.
     */
    protected function cleanMime(?string $mime): ?string
    {
        if (! $mime) {
            return null;
        }

        return trim(explode(';', $mime)[0]) ?: null;
    }

    protected function defaultName(string $type, ?string $mime): string
    {
        return match ($type) {
            'image'    => 'Imagen',
            'video'    => 'Video',
            'audio'    => 'Nota de voz',
            'sticker'  => 'Sticker',
            default    => 'Documento',
        };
    }

    /**
     * Extension from the mime, falling back to the original file name.
     *
     * It matters beyond tidiness: MediaController serves the stored mime, but
     * anyone who downloads the file gets this name, and a receipt saved without
     * an extension will not open by double-click.
     */
    protected function extension(?string $mime, string $name): string
    {
        $known = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'video/mp4'       => 'mp4',
            'video/3gpp'      => '3gp',
            'audio/ogg'       => 'ogg',
            'audio/mpeg'      => 'mp3',
            'audio/mp4'       => 'm4a',
            'audio/aac'       => 'aac',
            'audio/amr'       => 'amr',
            'application/pdf' => 'pdf',
        ];

        if ($mime && isset($known[$mime])) {
            return $known[$mime];
        }

        $fromName = pathinfo($name, PATHINFO_EXTENSION);

        return $fromName ?: 'bin';
    }
}
