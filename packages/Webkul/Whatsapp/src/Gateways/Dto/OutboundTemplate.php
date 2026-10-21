<?php

namespace Webkul\Whatsapp\Gateways\Dto;

use InvalidArgumentException;

/**
 * Una plantilla aprobada por Meta, lista para enviar.
 *
 * Se construye desde el catálogo de `whatsapp.templates` y no desde datos
 * sueltos: así un nombre mal escrito o un parámetro de menos falla al armarla,
 * con el motivo nombrado, en lugar de volver como «(#132000) Number of
 * parameters does not match» desde Graph API cuando el job ya corrió.
 */
final class OutboundTemplate
{
    /**
     * @param  list<string>  $params  Valores de {{1}}, {{2}}... en orden.
     * @param  list<string>  $buttons  Solo para mostrarlos en el inbox; Meta ya los tiene.
     */
    public function __construct(
        public string $name,
        public string $language,
        public string $body,
        public array $params = [],
        public array $buttons = [],
    ) {}

    /**
     * @param  list<string>  $params
     */
    public static function fromCatalog(string $name, array $params = []): self
    {
        $definicion = config("whatsapp.templates.{$name}");

        if (! is_array($definicion)) {
            throw new InvalidArgumentException("La plantilla '{$name}' no está en whatsapp.templates.");
        }

        $esperados = preg_match_all('/\{\{\d+\}\}/', (string) $definicion['body']);

        if (count($params) !== $esperados) {
            throw new InvalidArgumentException(
                "La plantilla '{$name}' lleva {$esperados} parámetro(s) y se pasaron ".count($params).'.'
            );
        }

        // Meta rechaza el envío entero si un parámetro va vacío.
        foreach ($params as $i => $valor) {
            if (trim((string) $valor) === '') {
                throw new InvalidArgumentException("El parámetro {{".($i + 1)."}} de '{$name}' está vacío.");
            }
        }

        return new self(
            name: $name,
            language: (string) $definicion['language'],
            body: (string) $definicion['body'],
            params: array_values(array_map('strval', $params)),
            buttons: (array) ($definicion['buttons'] ?? []),
        );
    }

    /**
     * El texto tal como lo leyó el cliente.
     */
    public function rendered(): string
    {
        return preg_replace_callback(
            '/\{\{(\d+)\}\}/',
            fn (array $m) => $this->params[(int) $m[1] - 1] ?? $m[0],
            $this->body,
        );
    }

    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'language' => $this->language,
            'body'     => $this->body,
            'params'   => $this->params,
            'buttons'  => $this->buttons,
        ];
    }

    /**
     * Desde el `payload` guardado. No vuelve a validar contra el catálogo: lo
     * que se encoló es lo que se manda, aunque la config cambie en el medio.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            language: (string) ($data['language'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            params: (array) ($data['params'] ?? []),
            buttons: (array) ($data['buttons'] ?? []),
        );
    }
}
