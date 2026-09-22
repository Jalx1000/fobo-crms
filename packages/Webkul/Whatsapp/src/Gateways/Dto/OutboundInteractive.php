<?php

namespace Webkul\Whatsapp\Gateways\Dto;

use Webkul\Whatsapp\Exceptions\InteractiveMessageException;

/**
 * Un menú tocable en camino al cliente: botones de respuesta o una lista.
 *
 * Los límites son de WhatsApp, no nuestros, y se verifican al construir el
 * objeto. La alternativa —mandarlo y ver qué dice Meta— devuelve "invalid
 * parameter" sin decir qué parámetro, contra un endpoint de producción.
 *
 * Botones y lista son el mismo objeto porque para todo lo que sigue —el job,
 * la columna `payload`, la burbuja del inbox— son la misma cosa con distinto
 * envoltorio. Sólo el gateway los distingue, que es donde la diferencia existe
 * de verdad.
 */
final class OutboundInteractive
{
    public const BUTTONS = 'buttons';

    public const LIST = 'list';

    /** WhatsApp: hasta 3 botones de respuesta. */
    public const MAX_BUTTONS = 3;

    /** WhatsApp: hasta 10 filas EN TOTAL, repartidas en las secciones que sea. */
    public const MAX_ROWS = 10;

    public const MAX_BUTTON_TITLE = 20;

    public const MAX_ROW_TITLE = 24;

    public const MAX_ROW_DESCRIPTION = 72;

    public const MAX_BODY = 1024;

    public const MAX_FOOTER = 60;

    public const MAX_HEADER = 60;

    /** El label del botón que abre la lista. */
    public const MAX_LIST_BUTTON = 20;

    /**
     * @param  array<int, array{id: string, title: string, description?: ?string}>  $options
     */
    private function __construct(
        public string $kind,
        public string $body,
        public array $options,
        public ?string $header = null,
        public ?string $footer = null,
        public ?string $listButton = null,
    ) {}

    /**
     * Botones de respuesta. El formato más directo, y el más limitado: 3
     * opciones, 20 caracteres, sin descripción.
     */
    public static function buttons(
        string $body,
        array $options,
        ?string $header = null,
        ?string $footer = null,
    ): self {
        self::assertBody($body);
        self::assertOptional('header', $header, self::MAX_HEADER);
        self::assertOptional('footer', $footer, self::MAX_FOOTER);

        if (count($options) === 0) {
            throw InteractiveMessageException::empty('botones');
        }

        if (count($options) > self::MAX_BUTTONS) {
            throw InteractiveMessageException::tooMany('botones', count($options), self::MAX_BUTTONS);
        }

        return new self(
            kind: self::BUTTONS,
            body: $body,
            options: self::normalizeOptions($options, self::MAX_BUTTON_TITLE, withDescription: false),
            header: $header,
            footer: $footer,
        );
    }

    /**
     * Lista. Hasta 10 filas y 24 caracteres por título, más una descripción
     * opcional — es el formato que sirve para cualquier menú que no entre en
     * tres botones.
     */
    public static function list(
        string $body,
        array $options,
        ?string $listButton = null,
        ?string $header = null,
        ?string $footer = null,
    ): self {
        self::assertBody($body);
        self::assertOptional('header', $header, self::MAX_HEADER);
        self::assertOptional('footer', $footer, self::MAX_FOOTER);

        if (count($options) === 0) {
            throw InteractiveMessageException::empty('filas');
        }

        if (count($options) > self::MAX_ROWS) {
            throw InteractiveMessageException::tooMany('filas', count($options), self::MAX_ROWS);
        }

        $listButton = $listButton ?: (string) config('whatsapp.interactive.list_button', 'Ver opciones');

        if (mb_strlen($listButton) > self::MAX_LIST_BUTTON) {
            throw InteractiveMessageException::tooLong(
                'botón de la lista',
                mb_strlen($listButton),
                self::MAX_LIST_BUTTON,
            );
        }

        return new self(
            kind: self::LIST,
            body: $body,
            options: self::normalizeOptions($options, self::MAX_ROW_TITLE, withDescription: true),
            header: $header,
            footer: $footer,
            listButton: $listButton,
        );
    }

    /**
     * Reconstruir desde la columna `payload` de un mensaje ya guardado. Es lo
     * que hace el job al reintentar: lo que el controlador tenía en memoria no
     * sobrevive, la fila sí.
     */
    public static function fromArray(array $data): self
    {
        $options = (array) ($data['options'] ?? []);
        $body = (string) ($data['body'] ?? '');

        return ($data['kind'] ?? self::LIST) === self::BUTTONS
            ? self::buttons($body, $options, $data['header'] ?? null, $data['footer'] ?? null)
            : self::list($body, $options, $data['list_button'] ?? null, $data['header'] ?? null, $data['footer'] ?? null);
    }

    public function toArray(): array
    {
        return [
            'kind'        => $this->kind,
            'body'        => $this->body,
            'options'     => $this->options,
            'header'      => $this->header,
            'footer'      => $this->footer,
            'list_button' => $this->listButton,
        ];
    }

    /**
     * Lo que se guarda en `messages.body` y se muestra en la lista de
     * conversaciones. Sin esto el preview del chat queda vacío en el mensaje
     * que justamente pedía una respuesta.
     */
    public function label(): string
    {
        return $this->body;
    }

    /**
     * @param  array<int, array{id: string, title: string, description?: ?string}>  $options
     * @return array<int, array{id: string, title: string, description?: ?string}>
     */
    private static function normalizeOptions(array $options, int $maxTitle, bool $withDescription): array
    {
        $normalized = [];

        foreach (array_values($options) as $index => $option) {
            $title = trim((string) ($option['title'] ?? ''));
            $id = trim((string) ($option['id'] ?? '')) ?: 'opt:'.($index + 1);

            if ($title === '') {
                throw InteractiveMessageException::empty('título de la opción '.($index + 1));
            }

            if (mb_strlen($title) > $maxTitle) {
                throw InteractiveMessageException::tooLong('título de "'.$title.'"', mb_strlen($title), $maxTitle);
            }

            $row = ['id' => $id, 'title' => $title];

            if ($withDescription && ! empty($option['description'])) {
                $description = trim((string) $option['description']);

                if (mb_strlen($description) > self::MAX_ROW_DESCRIPTION) {
                    throw InteractiveMessageException::tooLong(
                        'descripción de "'.$title.'"',
                        mb_strlen($description),
                        self::MAX_ROW_DESCRIPTION,
                    );
                }

                $row['description'] = $description;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private static function assertBody(string $body): void
    {
        if (trim($body) === '') {
            throw InteractiveMessageException::empty('body');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw InteractiveMessageException::tooLong('body', mb_strlen($body), self::MAX_BODY);
        }
    }

    private static function assertOptional(string $campo, ?string $valor, int $limite): void
    {
        if ($valor !== null && mb_strlen($valor) > $limite) {
            throw InteractiveMessageException::tooLong($campo, mb_strlen($valor), $limite);
        }
    }
}
