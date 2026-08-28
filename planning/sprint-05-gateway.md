# Sprint G — Capa de gateway multi-proveedor

**Duración:** 1 semana (~5 días-dev) · **Prioridad: ALTA, va ANTES de media.**

> **Por qué antes de media:** si se implementa `sendDocument`/descarga de archivos contra Cloud API
> directamente, hay que reescribirlo cuando llegue el gateway. Hacer el gateway primero significa
> escribir el código de media **una sola vez**, ya detrás del contrato.

## Objetivo

Que el proveedor de mensajería sea intercambiable por config (`WHATSAPP_GATEWAY`), sin que el
núcleo ni el front sepan cuál está activo. Al terminar, Cloud API debe seguir funcionando
**exactamente igual que hoy**, pero detrás del contrato.

## Contexto: las fugas actuales

| Fuga | Dónde | A dónde se mueve |
|---|---|---|
| Parseo `entry → changes → value` | `IngestInboundPayload` | `CloudApiGateway::parseWebhook()` |
| `config('whatsapp.cloud.app_secret')` | `WebhookController` | `CloudApiGateway::authenticateWebhook()` |
| `new CloudApi` / inyección directa | `SendMessage` | `GatewayManager::for($conversation)` |
| `hub.challenge` | `WebhookController` | `CloudApiGateway::verifyWebhook()` |

## Historias

### HU-G1 · Contrato + DTOs canónicos — 1d `[BACK]`
`Gateways/Contracts/Gateway.php` + DTOs: `InboundBatch`, `InboundMessage`, `StatusUpdate`,
`ContactIdentity`, `MediaRef`, `StoredMedia`, `OutboundMedia`, `SendResult`, `Capabilities`,
`WebhookAuth`, enum `WebhookSecurity`.
**AC:**
- El contrato recibe `Conversation` (no `string $to`) en los métodos de envío.
- `ContactIdentity` admite teléfono **o** `providerId`.
- Ningún DTO menciona un proveedor concreto.

### HU-G2 · `GatewayManager` + registro por config — 0.5d `[BACK]`
`active()`, `driver(key)`, `for(Conversation)`. Registro en `config('whatsapp.gateways')`, cada
driver recibe **su propio array de config inyectado**.
**AC:**
- `WHATSAPP_GATEWAY` inexistente o mal configurado → excepción clara al arrancar, no en runtime.
- El núcleo no lee config de ningún driver concreto.

### HU-G3 · `BaseGateway` + modelo de seguridad del webhook — 1d `[BACK · SEGURIDAD]`
Helpers `hmacMatches()` (sobre **raw body**), `urlSecretMatches()`, `headerTokenMatches()`, todos con
`hash_equals`. Ruta pasa a `/api/v1/whatsapp/webhook/{secret?}` (sigue siendo **un solo webhook**).
**AC:**
- `authenticateWebhook()` devuelve `WebhookAuth` con motivo; el motivo se loguea en el rechazo.
- `webhookSecurity()` declarado por driver; si es `NONE`, se loguea warning al arrancar.
- La ruta legacy `/api/v1/whatsapp/webhook` (sin secret) sigue funcionando → **no rompe Meta**.

### HU-G4 · `CloudApiGateway` — migrar lo existente — 1.5d `[BACK]`
Absorbe `Services/CloudApi` + el parseo de Meta que hoy vive en `IngestInboundPayload`.
Implementa: `verifyWebhook` (hub.challenge), `authenticateWebhook` (HMAC `X-Hub-Signature-256`),
`parseWebhook` (entry→changes→value → `InboundBatch`), `sendText`, mapeo de estados nativos →
canónicos.
**AC:**
- `capabilities()->send === ['text']` (lo realmente implementado hoy).
- **Envío y recepción de texto siguen funcionando idénticos** contra el número real.
- `IngestInboundPayload` y `WebhookController` ya no contienen ni una referencia a Meta.

### HU-G5 · Núcleo agnóstico + `capabilities` al front — 1d `[BACK · FRONT]`
`WebhookController`, `IngestInboundPayload`, `SendMessage` pasan por `GatewayManager`.
`GET thread` devuelve `capabilities`; el composer oculta lo no soportado.
**AC:**
- Con `send: ['text']`, el botón de adjuntar **no se muestra**.
- Al agregar `'document'` a capabilities, el botón aparece sin tocar el front.

### HU-G6 · Migración de esquema + `whatsapp:webhook-info` — 0.5d `[BACK]`
Columnas `gateway` y `provider_conversation_id` en `whatsapp_conversations` (default `cloud_api`).
Comando que imprime la URL a pegar en el panel del proveedor activo + credenciales esperadas +
postura de seguridad.
**AC:** migración idempotente; datos existentes quedan marcados `cloud_api`.

### HU-G7 · `KommoGateway` — ⛔ BLOQUEADO — `[BACK]`
**Bloqueado por:** documentación de la API de mensajería de Kommo (o credenciales de prueba).
No se inventan endpoints. Cuando llegue la doc, se implementa contra el contrato ya probado.
**AC:** `authenticateWebhook()` vía URL-secret (categoría B), `parseWebhook()` según su formato,
`capabilities()` según lo implementado.

## Definición de Hecho del sprint

1. `WHATSAPP_GATEWAY=cloud_api` → **todo funciona igual que antes del refactor** (probado con envío
   y recepción reales).
2. Un `grep -ri "entry\|changes\|hub_challenge\|graph.facebook" src/` **no devuelve nada fuera de**
   `Gateways/CloudApi/`.
3. El front no contiene ninguna referencia a un proveedor.

## Riesgo principal

Este es un refactor de código **que ya funciona en producción**. Mitigación: se hace en un solo
sprint, con Cloud API como único driver, y se valida con un envío/recepción real antes de tocar
nada más. No se agrega Kommo hasta que este paso esté verde.
