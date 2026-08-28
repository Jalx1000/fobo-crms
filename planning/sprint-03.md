# Sprint 3 — Agente IA (switch + webhook saliente) + Swagger

**Duración:** 1 semana (~5 días-dev) · **Objetivo:** activar/desactivar el agente IA por
conversación (con default global), notificar al agente los mensajes entrantes y exponer la API
documentada para que el agente responda. Agente agnóstico (n8n u otro).

## Historias

### HU-12 · Switch IA global + por conversación — 1d `[BACK · FRONT]`
- Global vía `SystemConfig` `whatsapp.ai.enabled`.
- Por conversación `whatsapp_conversations.ai_enabled` (nullable → `null` hereda global).
- Resolver efectivo: `ai_enabled ?? global`.
**AC:** con global ON pero conversación en OFF, la IA **no** responde a ese lead.

### HU-13 · Webhook saliente al agente (payload contractual) — 1.5d `[BACK]`
Al persistir un mensaje entrante y si la IA efectiva está ON, `POST` (encolado) al
`whatsapp.agent.webhook_url` con el payload del contrato (ver README): `contact`, `lead`,
`message`, `history` (últimos N) y `reply_to`.
**AC:**
- n8n recibe exactamente el JSON del contrato.
- Con IA OFF **no** se dispara el webhook.
- Reintentos con backoff si el agente no responde 2xx.

### HU-14 · Endpoint de respuesta del agente (auth API key de usuario) — 1d `[BACK]`
El agente responde con `POST /api/v1/whatsapp/conversations/{id}/messages` autenticado con la
**API key del usuario** (Sanctum de `krayin/rest-api`). El mensaje se marca `sender=ia`.
**AC:**
- Token/API key inválido → `401`.
- Mensaje del agente se envía por el mismo `SendJob` del Sprint 2 y se marca como IA.

### HU-15 · Documentación OpenAPI / Swagger — 1d `[BACK]`
Anotar (OpenAPI, `l5-swagger` ya instalado) todos los endpoints del inbox: webhook, listar
mensajes, enviar mensaje. Regenerar y verificar en `/api/documentation`.
**AC:** n8n puede importar el spec y ver request/response de "enviar mensaje".

### HU-16 · Toggle IA en el front + autoría — 0.5d `[FRONT]`
- Switch de IA en el header de la conversación (refleja/edita `ai_enabled`).
- Etiqueta por mensaje: quién respondió (IA / humano / cliente).
**AC:** cambiar el toggle actualiza el estado y se ve el autor de cada burbuja.

## Dependencias
- Sprint 2 (envío) — el agente responde por el mismo endpoint/`SendJob`.

## Definición de Hecho del sprint
Con IA ON en una conversación, un mensaje entrante dispara el webhook a n8n; n8n responde vía la API
documentada y el cliente recibe la respuesta por WhatsApp. Con IA OFF, el agente humano tiene el
control y n8n no interviene.
