# Sprint 2 — Envío de mensajes + estados de entrega

**Duración:** 1 semana (~5 días-dev) · **Objetivo:** que un agente humano responda desde el CRM y
envíe texto, imagen, documento, audio y reply, viendo el estado de entrega (✓/✓✓).

## Historias

### HU-09 · Endpoint de envío + `SendJob` → Cloud API — 2d `[BACK]`
- `POST /api/v1/whatsapp/conversations/{id}/messages` (body: `type`, `text`, adjunto, `reply_to_id`).
- Persiste mensaje `direction=outbound`, `status=queued`, `sender=human` y encola `SendJob`.
- `SendJob`: para media hace `POST /{phone-number-id}/media` (upload) y luego
  `POST /{phone-number-id}/messages` con el `media_id`; para reply agrega `context.message_id`.
- Actualiza `wa_message_id` y `status=sent`; en error `status=failed` + log.
**AC:**
- Los 5 tipos MVP (texto/imagen/documento/audio/reply) salen y llegan al teléfono.
- Fallo de Cloud API deja el mensaje en `failed`, no rompe el request.

### HU-10 · Webhook de statuses (delivered/read/failed) — 1d `[BACK]`
Completar la rama `statuses[]` del `IngestJob`: mapear `sent/delivered/read/failed` al mensaje por
`wa_message_id` y broadcast del cambio de estado.
**AC:** el estado del mensaje se actualiza y se refleja en el front vía Echo.

### HU-11 · Composer en el front + estados — 2d `[FRONT]`
- Input de texto + botón adjuntar (imagen/documento/audio) + acción "responder" (cita el mensaje).
- Envío optimista (mensaje aparece como `queued` antes de confirmar).
- Indicadores de estado por mensaje: reloj (queued) → ✓ (sent) → ✓✓ (delivered) → ✓✓ azul (read).
**AC:**
- Reply referencia visualmente el mensaje citado.
- El estado de cada mensaje saliente se actualiza en vivo sin recargar.

## Limitación a documentar (no es bug)
Meta Cloud API solo permite mensajes de **texto libre dentro de la ventana de 24h** desde el último
mensaje del cliente. Fuera de esa ventana se requieren **plantillas (templates) aprobadas**. En el
MVP: si la ventana está cerrada, el composer se deshabilita y muestra el aviso. Plantillas quedan
como feature post-MVP.

## Dependencias
- Sprint 1 (inbox visor + Reverb).

## Definición de Hecho del sprint
Un agente responde desde el CRM (texto + imagen + un reply); el cliente los recibe en WhatsApp y el
CRM muestra la transición de estados hasta ✓✓.
