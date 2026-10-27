<?php

namespace Webkul\Whatsapp\Services;

/**
 * El saludo a un contacto nuevo.
 *
 * Va a un número con el que nunca hablamos, así que cae fuera de la ventana de 24 h de
 * WhatsApp y sólo puede salir como PLANTILLA APROBADA por Meta. Todo lo que eso implica
 * —que la plantilla exista, que el encabezado coincida con lo aprobado, que nada de
 * esto pueda voltear el alta del contacto— vive en EnvioDePlantilla, porque el
 * recordatorio de recompra tropieza con exactamente las mismas piedras.
 *
 * Lo propio del saludo queda acá: a quién se saluda, con qué datos y bajo qué bandera.
 */
class Bienvenida
{
    public function __construct(
        protected EnvioDePlantilla $envio,
        protected VideoDeBienvenida $video,
    ) {}

    /**
     * Encola el saludo. Devuelve si quedó encolado.
     */
    public function enviarA(string $telefono, ?string $nombre = null): bool
    {
        $config = (array) config('whatsapp.bienvenida');

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        $enviar = fn (string $template, string $encabezado) => $this->envio->enviar(
            telefono: $telefono,
            nombre: $nombre,
            template: $template,
            language: (string) $config['language'],
            // El nombre, o "cliente": una variable vacía deja el saludo diciendo
            // "¡Hola !", que se ve peor que un saludo genérico.
            variables: [trim((string) $nombre) ?: 'cliente'],
            imagen: $encabezado,
            motivo: 'la bienvenida',
        );

        /*
         * Con video si el número está en la lista. Si la plantilla con video todavía
         * no está aprobada, sale la de siempre: el cliente se queda sin video, no sin
         * saludo.
         */
        $conVideo = $this->video->aplicaA($telefono)
            ? $enviar((string) ($config['video_template'] ?? ''), $this->video->url())
            : null;

        return (bool) ($conVideo ?? $enviar((string) $config['template'], (string) ($config['logo'] ?? '')));
    }
}
