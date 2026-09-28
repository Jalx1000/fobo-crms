<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Un mensaje interactivo saliente: botones de respuesta o lista de opciones.
 *
 * Los dos formatos comparten cuerpo, encabezado y pie, y se diferencian en lo
 * que ofrecen: `button` muestra hasta 3 botones planos; `list` abre un menú
 * desplegable con secciones y hasta 10 filas.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Los límites de abajo son de Meta y se validan ACÁ, no en el gateway.
 *
 * Meta rechaza un interactivo que se pasa de largo con un 400 genérico que no
 * dice cuál campo fue. Validando en el DTO, el error nombra el campo y el
 * límite, y llega al agente como un 422 accionable en vez de un "Bad Request"
 * que obliga a leer el payload a mano.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class OutboundInteractive
{
    /** Encabezado, cuerpo y pie. El cuerpo es lo único obligatorio. */
    public const MAX_HEADER = 60;

    public const MAX_BODY = 1024;

    public const MAX_FOOTER = 60;

    /** Botones de respuesta. */
    public const MAX_BUTTONS = 3;

    public const MAX_BUTTON_TITLE = 20;

    public const MAX_BUTTON_ID = 256;

    /** Lista. El tope de 10 filas es sobre el TOTAL, no por sección. */
    public const MAX_LIST_BUTTON = 20;

    public const MAX_SECTIONS = 10;

    public const MAX_ROWS = 10;

    public const MAX_SECTION_TITLE = 24;

    public const MAX_ROW_TITLE = 24;

    public const MAX_ROW_DESCRIPTION = 72;

    public const MAX_ROW_ID = 200;

    /**
     * @param  'button'|'list'  $format
     * @param  array<int, array{id: string, title: string}>  $buttons
     * @param  array<int, array{title?: string, rows: array<int, array{id: string, title: string, description?: string}>}>  $sections
     */
    public function __construct(
        public string $format,
        public string $body,
        public ?string $header = null,
        public ?string $footer = null,
        public array $buttons = [],
        public string $listButton = '',
        public array $sections = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            format: $data['format'] ?? 'button',
            body: (string) ($data['body'] ?? ''),
            header: $data['header'] ?? null,
            footer: $data['footer'] ?? null,
            buttons: $data['buttons'] ?? [],
            listButton: (string) ($data['list_button'] ?? ''),
            sections: $data['sections'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'format'      => $this->format,
            'body'        => $this->body,
            'header'      => $this->header,
            'footer'      => $this->footer,
            'buttons'     => $this->buttons,
            'list_button' => $this->listButton,
            'sections'    => $this->sections,
        ];
    }

    /**
     * Valida contra los límites de Meta. Devuelve el primer problema, o null.
     *
     * Devuelve string en vez de lanzar: quien llama decide si es un 422 para el
     * agente o una excepción. Lanzar acá obligaría a envolver cada construcción
     * en un try.
     */
    public function problema(): ?string
    {
        if (! in_array($this->format, ['button', 'list'], true)) {
            return "El formato debe ser 'button' o 'list', llegó '{$this->format}'.";
        }

        if (trim($this->body) === '') {
            return 'El cuerpo del mensaje no puede estar vacío.';
        }

        foreach ([
            ['cuerpo', $this->body, self::MAX_BODY],
            ['encabezado', $this->header, self::MAX_HEADER],
            ['pie', $this->footer, self::MAX_FOOTER],
        ] as [$campo, $valor, $max]) {
            if ($valor !== null && mb_strlen($valor) > $max) {
                return "El {$campo} supera los {$max} caracteres (tiene ".mb_strlen($valor).').';
            }
        }

        return $this->format === 'button'
            ? $this->problemaBotones()
            : $this->problemaLista();
    }

    protected function problemaBotones(): ?string
    {
        if (! $this->buttons) {
            return 'Un mensaje de botones necesita al menos un botón.';
        }

        if (count($this->buttons) > self::MAX_BUTTONS) {
            return 'WhatsApp admite como máximo '.self::MAX_BUTTONS.' botones, llegaron '.count($this->buttons).'. Para más opciones usá una lista.';
        }

        $ids = [];

        foreach ($this->buttons as $i => $boton) {
            $n = $i + 1;

            if (($problema = $this->problemaOpcion("El botón {$n}", $boton, self::MAX_BUTTON_TITLE, self::MAX_BUTTON_ID)) !== null) {
                return $problema;
            }

            /*
            | Ids repetidos: Meta los acepta y después manda la misma respuesta
            | para dos botones distintos, así que la conversación se rompe recién
            | cuando el cliente elige. Cortar acá es lo único que lo hace visible.
            */
            if (in_array($boton['id'], $ids, true)) {
                return "El id '{$boton['id']}' está repetido: cada opción necesita uno propio.";
            }

            $ids[] = $boton['id'];
        }

        return null;
    }

    protected function problemaLista(): ?string
    {
        if (trim($this->listButton) === '') {
            return 'Una lista necesita el texto del botón que la abre.';
        }

        if (mb_strlen($this->listButton) > self::MAX_LIST_BUTTON) {
            return 'El texto del botón de la lista supera los '.self::MAX_LIST_BUTTON.' caracteres.';
        }

        if (! $this->sections) {
            return 'Una lista necesita al menos una sección con opciones.';
        }

        if (count($this->sections) > self::MAX_SECTIONS) {
            return 'WhatsApp admite como máximo '.self::MAX_SECTIONS.' secciones.';
        }

        $filas = 0;
        $ids = [];

        foreach ($this->sections as $s => $seccion) {
            $titulo = $seccion['title'] ?? null;

            if ($titulo !== null && mb_strlen($titulo) > self::MAX_SECTION_TITLE) {
                return 'El título de una sección supera los '.self::MAX_SECTION_TITLE.' caracteres: "'.$titulo.'".';
            }

            if (empty($seccion['rows'])) {
                return 'Cada sección necesita al menos una opción.';
            }

            foreach ($seccion['rows'] as $fila) {
                $filas++;

                if ($filas > self::MAX_ROWS) {
                    return 'WhatsApp admite como máximo '.self::MAX_ROWS.' opciones en total entre todas las secciones.';
                }

                if (($problema = $this->problemaOpcion('Una opción', $fila, self::MAX_ROW_TITLE, self::MAX_ROW_ID)) !== null) {
                    return $problema;
                }

                $descripcion = $fila['description'] ?? null;

                if ($descripcion !== null && mb_strlen($descripcion) > self::MAX_ROW_DESCRIPTION) {
                    return 'La descripción de "'.$fila['title'].'" supera los '.self::MAX_ROW_DESCRIPTION.' caracteres.';
                }

                if (in_array($fila['id'], $ids, true)) {
                    return "El id '{$fila['id']}' está repetido: cada opción necesita uno propio.";
                }

                $ids[] = $fila['id'];
            }
        }

        return null;
    }

    protected function problemaOpcion(string $etiqueta, array $opcion, int $maxTitulo, int $maxId): ?string
    {
        if (trim((string) ($opcion['id'] ?? '')) === '') {
            return "{$etiqueta} no tiene id. El id es lo que vuelve cuando el cliente elige.";
        }

        if (trim((string) ($opcion['title'] ?? '')) === '') {
            return "{$etiqueta} no tiene texto.";
        }

        if (mb_strlen($opcion['title']) > $maxTitulo) {
            return "{$etiqueta} supera los {$maxTitulo} caracteres: \"{$opcion['title']}\".";
        }

        if (mb_strlen((string) $opcion['id']) > $maxId) {
            return "{$etiqueta} tiene un id de más de {$maxId} caracteres.";
        }

        return null;
    }

    /**
     * El nodo `interactive` tal como lo espera la Cloud API.
     */
    public function toNode(): array
    {
        $nodo = array_filter([
            'type'   => $this->format,
            'header' => $this->header ? ['type' => 'text', 'text' => $this->header] : null,
            'body'   => ['text' => $this->body],
            'footer' => $this->footer ? ['text' => $this->footer] : null,
        ], fn ($v) => $v !== null);

        $nodo['action'] = $this->format === 'button'
            ? [
                'buttons' => array_map(fn ($b) => [
                    'type'  => 'reply',
                    'reply' => ['id' => (string) $b['id'], 'title' => $b['title']],
                ], $this->buttons),
            ]
            : [
                'button'   => $this->listButton,
                'sections' => array_map(fn ($s) => array_filter([
                    'title' => $s['title'] ?? null,
                    'rows'  => array_map(fn ($r) => array_filter([
                        'id'          => (string) $r['id'],
                        'title'       => $r['title'],
                        'description' => $r['description'] ?? null,
                    ], fn ($v) => $v !== null && $v !== ''), $s['rows']),
                ], fn ($v) => $v !== null && $v !== ''), $this->sections),
            ];

        return $nodo;
    }

    /**
     * Lo que el hilo muestra como cuerpo del mensaje.
     *
     * El interactivo no tiene texto plano, así que sin esto la lista de
     * conversaciones mostraría un renglón vacío donde hubo un menú.
     */
    public function label(): string
    {
        $opciones = $this->format === 'button'
            ? count($this->buttons)
            : array_sum(array_map(fn ($s) => count($s['rows'] ?? []), $this->sections));

        return $this->body."\n\n".($this->format === 'button'
            ? "[{$opciones} botón(es)]"
            : "[lista: {$opciones} opción(es)]");
    }
}
