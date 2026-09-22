<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;

/**
 * El veredicto del parser: o el mensaje se convierte en un menú, o se manda
 * como texto y se anota por qué.
 *
 * NO HAY UNA TERCERA RAMA QUE DEVUELVA UN ERROR, A PROPÓSITO. El agente cree
 * que está mandando un texto normal —no sabe que existe una validación acá— y
 * un 422 lo dejaría mudo frente al cliente, que es exactamente el fallo que
 * esto viene a arreglar. El motivo queda en `payload.interactive_skipped`, así
 * que la degradación es diagnosticable sin adivinar.
 */
final class ParsedInteractive
{
    private function __construct(
        public readonly ?OutboundInteractive $interactive,
        public readonly ?string $fallbackText = null,
        public readonly ?string $reason = null,
    ) {}

    public static function converted(OutboundInteractive $interactive): self
    {
        return new self($interactive);
    }

    public static function skipped(string $fallbackText, string $reason): self
    {
        return new self(null, $fallbackText, $reason);
    }

    public function isInteractive(): bool
    {
        return $this->interactive !== null;
    }
}
