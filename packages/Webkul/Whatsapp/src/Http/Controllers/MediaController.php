<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Serves an outgoing attachment to WhatsApp.
 *
 * Meta downloads the file from us, so this endpoint cannot sit behind auth. The
 * 40-char random filename is what keeps it private — the same protection the
 * files already had while served straight from public storage.
 *
 * It exists because the Content-Type has to match the type we declared when
 * sending. Served by the web server, a voice note recorded as `audio/mp4` goes
 * out as `video/mp4` (or `audio/x-m4a` for an .m4a), and WhatsApp accepts
 * neither for audio: the send fails with a generic error that says nothing
 * about mime types. Here the header is set from what was stored with the
 * message, so declaration and reality always agree.
 */
class MediaController extends Controller
{
    public function show(string $file): StreamedResponse
    {
        /*
         * Dos carpetas, un solo endpoint: `outbound` la descarga Meta desde
         * afuera, `inbound` la mira el asesor en el CRM. Comparten ruta para
         * que ambas pasen por el mismo control de mime.
         */
        $path = collect(['whatsapp/outbound/'.$file, 'whatsapp/inbound/'.$file])
            ->first(fn ($candidate) => Storage::disk('public')->exists($candidate));

        abort_if($path === null, 404);

        // The mime travels with the message, not with the file: it is what we
        // promised Meta, and that promise is what has to be kept here.
        $message = MessageProxy::modelClass()::query()
            ->where('media_path', 'like', '%'.$file)
            ->latest('id')
            ->first();

        $mime = $message?->media_mime
            ?: (Storage::disk('public')->mimeType($path) ?: 'application/octet-stream');

        return Storage::disk('public')->response($path, $message?->media_name, [
            'Content-Type'  => $mime,
            // Meta refetches on retry; a short cache keeps a redelivery cheap
            // without pinning a file we may have replaced.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
