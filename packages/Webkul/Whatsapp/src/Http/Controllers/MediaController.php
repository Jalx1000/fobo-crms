<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Serves an outgoing attachment to the messaging provider, which downloads it
 * by URL and therefore cannot authenticate.
 *
 * The point of the route is the Content-Type: it is taken from the message that
 * owns the file, so it always matches the media type declared when sending.
 * Letting the web server guess it breaks audio — an audio/mp4 voice note is an
 * MP4 container with no video track and gets served as video/mp4, which WhatsApp
 * refuses for an audio message.
 */
class MediaController extends Controller
{
    public function show(string $file): BinaryFileResponse
    {
        $path = 'whatsapp/'.$file;

        // The route already rejects slashes, so the name cannot climb out of the
        // folder; this only guards against a missing file.
        abort_unless(Storage::disk('public')->exists($path), 404);

        // Only files a message actually points at are reachable: an attachment
        // that was never sent stays private even if its name leaks.
        $message = MessageProxy::modelClass()::query()
            ->where('media_path', 'like', '%/'.$file)
            ->first();

        abort_unless($message, 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Content-Type'  => $message->media_mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
