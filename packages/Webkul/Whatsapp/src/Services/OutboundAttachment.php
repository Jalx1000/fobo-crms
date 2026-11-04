<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Whatsapp\Exceptions\AttachmentStorageException;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;

/**
 * Stages an outbound attachment: the file is stored on the `public` disk the
 * moment the advisor hits send, so the thread renders it immediately and the
 * queue job that talks to the provider only carries a path.
 *
 * Same layout as inbound media (whatsapp/media/{conversation}/…) so the shared
 * "Imágenes"/"Docs" tabs in the profile drawer pick both up without special
 * cases.
 */
class OutboundAttachment
{
    /**
     * Store the upload and describe it for both the message row and the driver.
     *
     * `path` is disk-relative (what the gateway reads to upload the bytes);
     * `url` is public (what the browser renders in the thread).
     *
     * @return array{path: string, url: string, mime: string|null, name: string}
     */
    public function stage(UploadedFile $file, int $conversationId): array
    {
        $extension = $file->getClientOriginalExtension()
            ?: $file->guessExtension()
            ?: '';

        $path = 'whatsapp/media/'.$conversationId.'/'.Str::random(24).($extension ? '.'.$extension : '');

        // putFileAs returns false instead of throwing. Swallowing that would
        // persist a message pointing at a file that was never written, and the
        // failure would only surface in the worker seconds later as a
        // misleading "the file is no longer available".
        $stored = Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false || ! Storage::disk('public')->exists($path)) {
            throw AttachmentStorageException::couldNotWrite($path);
        }

        return [
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
            'mime' => $file->getClientMimeType() ?: $file->getMimeType(),
            'name' => $file->getClientOriginalName() ?: basename($path),
        ];
    }

    /**
     * Store bytes we generated ourselves (a rendered quote PDF, a report) under
     * the same layout as an uploaded file.
     *
     * Same contract as stage(), so the caller builds the message row and the
     * gateway reads the file identically no matter where it came from.
     *
     * @return array{path: string, url: string, mime: string|null, name: string}
     */
    public function stageContents(
        string $contents,
        string $filename,
        string $mime,
        int $conversationId,
    ): array {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        $path = 'whatsapp/media/'.$conversationId.'/'.Str::random(24).($extension ? '.'.$extension : '');

        // Same reason as in stage(): put() returns false rather than throwing,
        // and a message row pointing at a file that was never written only
        // surfaces seconds later in the worker as a misleading error.
        if (Storage::disk('public')->put($path, $contents) === false
            || ! Storage::disk('public')->exists($path)
        ) {
            throw AttachmentStorageException::couldNotWrite($path);
        }

        return [
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
            'mime' => $mime,
            'name' => $filename,
        ];
    }

    /**
     * Decide where the advisor's text goes when they attach a file AND type a
     * message.
     *
     * WhatsApp only accepts a caption on image, video and document. Audio and
     * sticker reject the field outright - Meta answers 400 and the whole message
     * is lost - so the text has to go somewhere, and that is a product decision
     * rather than a technical one.
     *
     * `caption` rides along with the file; `follow_up` is sent as a separate
     * text message right after it (null = nothing extra).
     *
     * @param  string  $type  image|audio|video|document|sticker
     * @param  string|null  $text  What the advisor typed in the composer, if anything.
     * @return array{caption: string|null, follow_up: string|null}
     */
    public function planCaption(string $type, ?string $text): array
    {
        $text = trim((string) $text) ?: null;

        if ($text === null) {
            return ['caption' => null, 'follow_up' => null];
        }

        if (Capabilities::isCaptionable($type)) {
            return ['caption' => $text, 'follow_up' => null];
        }

        // Audio y sticker: el texto viaja como un mensaje aparte, encolado justo
        // detras del archivo. Descartarlo seria peor de lo que parece - el asesor
        // ve su texto salir del compositor y asume que llego, sin ninguna senal
        // de lo contrario.
        return ['caption' => null, 'follow_up' => $text];
    }
}
