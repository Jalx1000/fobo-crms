<?php

namespace Webkul\Whatsapp\Gateways;

use RuntimeException;

/**
 * El archivo excede el límite configurado.
 *
 * Es una excepción propia y no un fallo genérico porque el llamador tiene que
 * distinguirla: reintentar una descarga demasiado grande da exactamente el
 * mismo resultado tres veces. Se registra el motivo en el hilo y se termina.
 */
class MediaDemasiadoGrande extends RuntimeException
{
    public function __construct(public int $bytes, public int $limite)
    {
        parent::__construct(sprintf(
            'El archivo pesa %s y el límite es %s.',
            $this->humano($bytes),
            $this->humano($limite),
        ));
    }

    protected function humano(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
