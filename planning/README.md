# Inbox WhatsApp en el CRM — Planificación Scrum

Feature: convertir el visor solo-lectura `historial-ia.blade.php` en un **inbox tipo WhatsApp**
dentro del CRM, con recepción y envío de mensajes multimedia y un **switch de agente IA**
(n8n u otro) desacoplado vía API documentada.

## Decisiones tomadas

| # | Decisión | Elección |
|---|----------|----------|
| 1 | Proveedor WhatsApp | **WhatsApp Cloud API (Meta)** — oficial |
| 2 | Arquitectura del webhook | **Laravel es el hub**. El webhook apunta directo a Laravel; n8n queda como agente externo desacoplado |
| 3 | Tiempo real navegador | **Laravel Reverb + Echo** (websockets sobre Redis). Sin polling |
| 4 | Switch IA | **Global (SystemConfig) + override por conversación** |
| 5 | Envío multimedia MVP | texto + imagen + documento + audio + reply. (video/sticker/ubicación/contacto → post-MVP) |
| 6 | Identidad | **Por número de teléfono**, mapeado a `Person.contact_numbers` (E.164) |
| 7 | Historial n8n actual | **No se toca** (proyecto aparte). El endpoint `api/public/chat-history` queda como legacy solo-lectura |
| 8 | Anclaje de la conversación | **Person** (no Lead). El mismo componente se renderiza en la vista de lead y de person |
| 9 | Auth del agente | **API key de usuario** (Sanctum de `krayin/rest-api`), revocable por usuario |

## Arquitectura

```
WhatsApp (Meta Cloud API)
        │  webhook: GET verify (hub.challenge) / POST mensajes + statuses
        ▼
┌──────────────────────── LARAVEL (hub, fuente de verdad) ─────────────────────────┐
│  WhatsappWebhookController → valida firma X-Hub-Signature-256 → 200 → encola      │
│         │ cola Redis "whatsapp"                                                   │
│         ▼                                                                         │
│  IngestJob → resuelve phone→Person → conversation+message                        │
│            → descarga media (media_id → GET url → storage)                        │
│            → broadcast (Reverb) al navegador                                      │
│            → ¿ai_enabled? ── sí ──► OutboundWebhook al AGENTE (n8n/otro)          │
│                                       con payload contractual                     │
│  API REST (Swagger, auth API key de usuario):                                    │
│    GET  /api/v1/whatsapp/conversations/{id}/messages   ← histórico paginado      │
│    POST /api/v1/whatsapp/conversations/{id}/messages   ← el AGENTE responde aquí │
│                    │ cola → SendJob → Cloud API → guarda status                   │
└───────────────────────────────────────────────────────────────────────────────────┘
        ▲ websocket (Reverb / Echo)
  Inbox Blade+Vue (leads/view + persons/view)
```

### Por qué el webhook entrega al servidor pero igual se necesita Reverb
El webhook de Meta entrega el mensaje **al servidor Laravel**. La pestaña del navegador con el
chat abierto no se entera hasta que exista un canal **servidor → navegador**. Ese canal es Reverb
(websockets): al terminar el `IngestJob`, Laravel emite un evento broadcast y el navegador lo
recibe al instante. Sin esto habría que recargar la página.

## Contrato hacia el agente IA (webhook saliente)

Cuando llega un mensaje entrante y la conversación tiene IA activa, Laravel hace `POST` al webhook
configurado del agente con este payload **estable**:

```jsonc
{
  "event": "message.received",
  "conversation_id": 123,
  "ai_enabled": true,
  "contact":  { "phone": "+591...", "wa_name": "Juan", "person_id": 45, "lead_id": 78 },
  "lead":     { "title": "...", "pipeline": "...", "stage": "...", "value": 0 },
  "message":  { "id": 999, "type": "text|image|audio|document",
                "text": "hola", "media_url": null, "reply_to_id": null, "timestamp": "..." },
  "history":  [ /* últimos N mensajes: { role: "user|assistant", content, type } */ ],
  "reply_to": "/api/v1/whatsapp/conversations/123/messages"
}
```

El agente responde con `POST` a `reply_to` autenticado con su **API key de usuario**. Todo queda
documentado en Swagger (`/api/documentation`) para que n8n importe el spec.

## Modelo de datos

### `whatsapp_conversations`
`id`, `person_id?` (FK), `lead_id?` (FK, contexto opcional), `wa_phone` (E.164), `wa_name`,
`ai_enabled` (nullable bool → `null` hereda global), `status` (open/closed/unassigned),
`last_message_at`, `unread_count`, timestamps.

### `whatsapp_messages`
`id`, `conversation_id` (FK), `direction` (inbound/outbound), `type`
(text/image/document/audio/video/sticker/location/contact), `body` (text),
`media_path`, `media_mime`, `wa_message_id` (único → idempotencia), `reply_to_id` (self-FK),
`status` (queued/sent/delivered/read/failed), `sender` (contact/ia/agent/human), `payload` (json),
timestamps.

### Config global
`SystemConfig`: `whatsapp.ai.enabled` (bool), `whatsapp.agent.webhook_url`, `whatsapp.cloud.*`.

## Endpoints Cloud API usados (ref. `docs/whatsapp_cloud_api/`)
- Enviar: `POST /{phone-number-id}/messages`
- Subir media: `POST /{phone-number-id}/media`
- Descargar media: `GET /{media-id}` → url → `GET url`
- Webhook: verificación `GET` + recepción `POST` (entry→changes→value→messages/statuses)

## Sprints

| Sprint | Objetivo | Duración |
|--------|----------|----------|
| [Sprint 0](sprint-00.md) | Fundación: datos + webhook entrante + resolución de teléfono | 1 semana |
| [Sprint 1](sprint-01.md) | Infra Reverb + recepción multimedia + inbox visor | 1 semana |
| [Sprint 2](sprint-02.md) | Envío de mensajes + estados de entrega | 1 semana |
| **[Sprint G](sprint-05-gateway.md)** | **Capa de gateway multi-proveedor (Cloud API, Kommo, …)** | **1 semana** |
| [Sprint 3](sprint-03.md) | Agente IA (switch + webhook saliente) + Swagger | 1 semana |
| [Sprint 4](sprint-04.md) | Hardening: tests, rate-limit, idempotencia, extras | 1 semana |

> **Sprint G va antes de la media pendiente** (HU-06/09). Implementar media contra Cloud API
> directamente obligaría a reescribirla al llegar el gateway. Ver
> [arquitectura.md](arquitectura.md#capa-de-gateway-propuesta).

**Total estimado: 5 semanas** (1 dev full-time; con front+back en paralelo se comprime a ~3-4).

## Definición de Hecho (global)
- Código pasa `./vendor/bin/pint`.
- Tests Pest verdes para la lógica nueva.
- Sin claves ni tokens hardcodeados (todo por `.env` / SystemConfig).
- Endpoints nuevos anotados en Swagger.
