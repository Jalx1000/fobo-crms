<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;

/**
 * Convierte el texto del agente en un menú tocable de WhatsApp.
 *
 * El agente escribe:
 *
 *     ¿Desde qué ciudad nos escribís?
 *
 *     1.-) Santa Cruz
 *     2.-) Cochabamba
 *     3.-) La Paz
 *
 * ...y sale como tres botones. Sin tools nuevas del lado del agente: el cambio
 * es una frase en su prompt.
 *
 * EL MARCADOR ES ".-)" Y NO "1." A SECAS, A PROPÓSITO. De los 4 mensajes con
 * lista numerada que hay en el historial de producción, 2 son menús ("¿desde
 * qué ciudad nos escribís? 1. Santa Cruz...") y 2 son enumeraciones
 * informativas ("tenés 4 pedidos: 1. Pedido de 2 Promo Varietales, total Bs
 * 478..."). Convertir las segundas le pediría al cliente que elija un pedido
 * que nadie le ofreció, y cada ítem tiene 65 caracteres contra un límite de
 * 24 por fila. Detectar "1." es un 50 % de falsos positivos medido.
 */
class InteractiveParser
{
    /**
     * ¿Este texto pide un menú? Devuelve null cuando no —que es el caso
     * normal— y el caller sigue con un envío de texto como siempre.
     */
    public function parse(?string $text): ?ParsedInteractive
    {
        if (! config('whatsapp.interactive.enabled', true)) {
            return null;
        }

        $text = (string) $text;

        $items = $this->extractItems($text);

        // Menos de dos opciones no es un menú, es una viñeta.
        if (count($items) < 2) {
            return null;
        }

        // Numeración salteada (1, 2, 4) o que no arranca en 1: probablemente
        // no sea una lista de opciones sino una cita, una fecha, un precio.
        // Ante la duda no se toca el mensaje del agente.
        foreach ($items as $index => $item) {
            if ($item['number'] !== $index + 1) {
                return null;
            }
        }

        [$body, $footer] = $this->splitAround($text);

        // Sin pregunta ni contexto WhatsApp rechaza el mensaje: el body es
        // obligatorio. Es preferible degradar que perder el mensaje.
        if (trim($body) === '') {
            return ParsedInteractive::skipped($this->stripMarkers($text), 'sin texto antes de las opciones');
        }

        $titles = array_column($items, 'title');
        $longest = max(array_map('mb_strlen', $titles));

        if (count($items) > OutboundInteractive::MAX_ROWS) {
            return ParsedInteractive::skipped(
                $this->stripMarkers($text),
                sprintf('%d opciones; WhatsApp acepta %d', count($items), OutboundInteractive::MAX_ROWS),
            );
        }

        if ($longest > OutboundInteractive::MAX_ROW_TITLE) {
            return ParsedInteractive::skipped(
                $this->stripMarkers($text),
                sprintf(
                    'la opción más larga tiene %d caracteres; WhatsApp acepta %d',
                    $longest,
                    OutboundInteractive::MAX_ROW_TITLE,
                ),
            );
        }

        $options = [];

        foreach ($titles as $index => $title) {
            // El id lo genera el CRM. El agente nunca escribe uno, así que no
            // puede alucinar un id que no existe —que es lo que hizo en
            // producción con "ciudad_id: 12", un pipeline inexistente.
            $options[] = ['id' => 'opt:'.($index + 1), 'title' => $title];
        }

        /*
         * Botones cuando entran: se ven de una, sin abrir un menú. La lista
         * cubre todo lo demás. El límite de los botones es más chico (20 contra
         * 24), así que una opción de 22 caracteres cae a lista en vez de
         * romper.
         */
        $interactive = count($items) <= OutboundInteractive::MAX_BUTTONS
            && $longest <= OutboundInteractive::MAX_BUTTON_TITLE
                ? OutboundInteractive::buttons($body, $options, footer: $footer)
                : OutboundInteractive::list($body, $options, footer: $footer);

        return ParsedInteractive::converted($interactive);
    }

    /**
     * Las opciones marcadas, en orden de aparición.
     *
     * @return array<int, array{number: int, title: string}>
     */
    private function extractItems(string $text): array
    {
        if (! preg_match_all($this->pattern(), $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn ($m) => [
            'number' => (int) $m[1],
            'title'  => trim($m[2]),
        ], $matches);
    }

    /**
     * El texto de antes del primer ítem es la pregunta; el de después, una nota
     * al pie. Un footer más largo que el límite de Meta se anexa al body en vez
     * de perderse.
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitAround(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $before = [];
        $after = [];
        $seen = false;

        foreach ($lines as $line) {
            if (preg_match($this->pattern(), $line)) {
                $seen = true;

                continue;
            }

            $seen ? $after[] = $line : $before[] = $line;
        }

        $body = trim(implode("\n", $before));
        $footer = trim(implode("\n", $after));

        if ($footer === '') {
            return [$body, null];
        }

        if (mb_strlen($footer) > OutboundInteractive::MAX_FOOTER) {
            return [trim($body."\n\n".$footer), null];
        }

        return [$body, $footer];
    }

    /**
     * Reescribe "1.-) Item" como "1. Item".
     *
     * Es lo que sale cuando el menú no entra en los límites de WhatsApp: al
     * cliente le llega un mensaje legible y numerado normal, no el marcador
     * interno crudo.
     */
    public function stripMarkers(string $text): string
    {
        return preg_replace($this->pattern(), '$1. $2', $text) ?? $text;
    }

    /**
     * El marcador sale de config para poder cambiarlo sin deploy, pero se
     * escapa: es puntuación, y sin quotemeta ".-)"  se leería como regex.
     */
    private function pattern(): string
    {
        $marker = preg_quote((string) config('whatsapp.interactive.marker', '.-)'), '/');

        return '/^[ \t]*(\d{1,2})'.$marker.'[ \t]*(\S.*?)[ \t]*$/m';
    }
}
