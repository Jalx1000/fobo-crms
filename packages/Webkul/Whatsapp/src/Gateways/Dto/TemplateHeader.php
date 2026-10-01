<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * El encabezado de una plantilla, cuando la plantilla declara uno con contenido
 * variable.
 *
 * Existe porque una plantilla no es sólo su body. Meta valida el mensaje contra
 * la estructura EXACTA con la que fue creada: si la plantilla declara un header
 * de tipo DOCUMENT y el envío no lo lleva, la respuesta es
 * `(#132012) Parameter format does not match format in the created template`,
 * y el asesor sólo ve "No se envió" sin saber que falta un archivo.
 *
 * Un header estático (TEXT sin variables, como el "Hello World" de la plantilla
 * de ejemplo) NO necesita esto: Meta lo rellena solo y mandarle parámetros da
 * el mismo 132012 al revés.
 */
final class TemplateHeader
{
    /**
     * @param  string  $format  TEXT | IMAGE | VIDEO | DOCUMENT
     * @param  ?string  $link  URL pública del archivo, para los headers de media.
     * @param  ?string  $filename  Sólo DOCUMENT: sin esto el cliente ve un hash como nombre.
     * @param  array<int, string>  $variables  Sólo TEXT: los {{n}} del encabezado.
     */
    public function __construct(
        public readonly string $format,
        public readonly ?string $link = null,
        public readonly ?string $filename = null,
        public readonly array $variables = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            format: strtoupper((string) ($data['format'] ?? 'TEXT')),
            link: $data['link'] ?? null,
            filename: $data['filename'] ?? null,
            variables: array_values((array) ($data['variables'] ?? [])),
        );
    }

    public function toArray(): array
    {
        return [
            'format'    => $this->format,
            'link'      => $this->link,
            'filename'  => $this->filename,
            'variables' => $this->variables,
        ];
    }

    /**
     * ¿El header transporta un archivo? Determina si hace falta una URL pública.
     */
    public function isMedia(): bool
    {
        return in_array($this->format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true);
    }

    /**
     * El nombre del nodo que espera Meta dentro del parámetro: `document`,
     * `image` o `video` — en minúscula, que es como lo valida la Graph API.
     */
    public function mediaKind(): string
    {
        return strtolower($this->format);
    }
}
