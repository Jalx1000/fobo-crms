<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * An inbound message, normalized. Drivers guarantee providerMessageId — if the
 * provider gives none, the driver synthesizes a stable one so idempotency holds.
 */
final class InboundMessage
{
    public function __construct(
        public string $providerMessageId,
        public ContactIdentity $contact,
        public string $type = 'text',
        public ?string $body = null,
        public ?string $providerConversationId = null,
        public ?string $replyToProviderId = null,
        public array $raw = [],

        /*
        | Punteros al archivo, cuando el mensaje trae uno. Nullable y al final
        | para no romper ninguna construcción existente: los drivers que no
        | manejan archivos siguen llamando al constructor igual que antes.
        |
        | Es un ID del proveedor, no una URL: la URL de descarga de Meta caduca
        | en minutos, así que guardarla sería guardar algo que ya no sirve para
        | cuando el job intente usarla.
        */
        public ?string $mediaId = null,
        public ?string $mediaMime = null,
        public ?string $mediaFilename = null,
    ) {}

    /**
     * ¿Este mensaje trae un archivo para descargar?
     */
    public function tieneArchivo(): bool
    {
        return filled($this->mediaId);
    }
}
