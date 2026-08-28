<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Un archivo ya descargado del proveedor.
 *
 * Trae el contenido en memoria y no una ruta: quien descarga no decide dónde se
 * guarda. El límite de tamaño ya se aplicó antes de llegar acá, así que el
 * contenido siempre entra en memoria sin riesgo.
 */
final class DownloadedMedia
{
    public function __construct(
        public string $contents,
        public string $mime,
        public int $size,
        public ?string $filename = null,
    ) {}

    /**
     * Extensión razonable a partir del mime, para nombrar el archivo cuando el
     * proveedor no manda nombre — que es lo normal en fotos y audios.
     */
    public function extension(): string
    {
        return match (true) {
            str_contains($this->mime, 'jpeg')    => 'jpg',
            str_contains($this->mime, 'png')     => 'png',
            str_contains($this->mime, 'webp')    => 'webp',
            str_contains($this->mime, 'mp4')     => 'mp4',
            str_contains($this->mime, 'ogg')     => 'ogg',
            str_contains($this->mime, 'mpeg')    => 'mp3',
            str_contains($this->mime, 'pdf')     => 'pdf',
            default                              => 'bin',
        };
    }
}
