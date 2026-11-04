<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Lead\Models\Stage;
use Webkul\Lead\Services\EtapaPedido;
use Webkul\Whatsapp\Models\Message;

/**
 * Cuando un asesor le escribe al cliente, el pedido pasa a SOLICITUD RECIBIDA.
 *
 * Hasta el 28/10 solo la cotización de la IA lo movía: un pedido que el asesor
 * ya estaba atendiendo seguía en NO ATENDIDOS, y el tablero lo contaba como
 * sin atender. Ahora cuenta cualquier mensaje con `sender = 'human'`, venga de
 * donde venga: el inbox del CRM, Productos, el envío de un documento, o lo que
 * el asesor escribió en Kommo o en la app de WhatsApp Business (ecos).
 *
 * Solo avanza desde NO ATENDIDOS: un pedido que ya está más adelante no
 * retrocede (ver EtapaPedido).
 */
class IntervencionAsesor
{
    public const ETAPA = 'solicitud-recibida';

    public function __construct(protected EtapaPedido $etapas) {}

    /**
     * Un mensaje que un asesor le mandó al cliente. Los de la IA (`ia`) y los
     * automáticos del CRM (`system`, el recontacto) no son una intervención.
     */
    public function esIntervencion(Message $message): bool
    {
        return $message->direction === 'outbound' && $message->sender === 'human';
    }

    /**
     * Si el mensaje es de un asesor, mueve el pedido de su conversación.
     * Devuelve si lo movió.
     */
    public function registrar(Message $message): bool
    {
        if (! $this->esIntervencion($message)) {
            return false;
        }

        $leadId = $message->conversation?->lead_id;

        if (! $leadId) {
            return false;
        }

        return $this->etapas->mover((int) $leadId, self::ETAPA, Stage::NO_ATENDIDOS);
    }
}
