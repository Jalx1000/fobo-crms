<?php

namespace Webkul\Whatsapp\Exceptions;

use RuntimeException;

/**
 * Un mensaje interactivo que no cumple los límites de WhatsApp.
 *
 * Se lanza al construir el DTO y no al postear a Meta, porque Graph API
 * contesta "invalid parameter" y nada más: sin esto, un título de 25
 * caracteres son treinta minutos de bisección contra un endpoint de
 * producción. El mensaje nombra siempre los tres datos —qué campo, cuánto
 * tenía, cuánto se permite— que es lo que hace falta para arreglarlo sin
 * volver a leer la documentación de Meta.
 */
class InteractiveMessageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $campo,
        public readonly int $valor,
        public readonly int $limite,
    ) {
        parent::__construct($message);
    }

    public static function tooMany(string $que, int $cuantos, int $limite): self
    {
        return new self(
            sprintf('WhatsApp acepta hasta %d %s; se intentó enviar %d.', $limite, $que, $cuantos),
            $que,
            $cuantos,
            $limite,
        );
    }

    public static function tooLong(string $campo, int $largo, int $limite): self
    {
        return new self(
            sprintf('El campo "%s" acepta hasta %d caracteres; tiene %d.', $campo, $limite, $largo),
            $campo,
            $largo,
            $limite,
        );
    }

    public static function empty(string $campo): self
    {
        return new self(
            sprintf('El campo "%s" es obligatorio en un mensaje interactivo.', $campo),
            $campo,
            0,
            1,
        );
    }

    /**
     * 422: el pedido llegó bien formado, el contenido es el que no sirve.
     */
    public function status(): int
    {
        return 422;
    }
}
