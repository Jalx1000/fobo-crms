# Sprint 0 — Fundación: datos + webhook entrante + resolución de teléfono

**Duración:** 1 semana (~5 días-dev) · **Objetivo:** que un mensaje entrante de WhatsApp llegue a
Laravel, se persista y quede asociado al `Person` correcto. Sin UI todavía.

## Alcance
Módulo nuevo `Webkul\Whatsapp` (o dentro de `Admin` si se prefiere no crear módulo). Migraciones,
modelos, repos, controlador de webhook, job de ingesta y resolución de teléfono.

> Nota Krayin: un módulo nuevo se registra en `config/concord.php` (`modules[]`) **y** en el
> autoload PSR-4 de `composer.json`. Migraciones/rutas se cargan desde su `ModuleServiceProvider`.

## Historias

### HU-01 · Migraciones + modelos + repos — 1.5d `[BACK]`
Crear `whatsapp_conversations` y `whatsapp_messages` (ver modelo de datos en README), modelos
Eloquent con contratos, y repositorios extendiendo `Webkul\Core\Eloquent\Repository`.
**AC:**
- `wa_message_id` con índice único.
- `conversation.person()` y `message.replyTo()` relacionados; `message.conversation()`.
- `php artisan migrate` corre limpio y revierte con `migrate:rollback`.

### HU-02 · Webhook Meta: verificación + recepción + firma — 1d `[BACK]`
`WhatsappWebhookController`:
- `GET /api/v1/whatsapp/webhook` → responde `hub.challenge` si `hub.verify_token` coincide con env.
- `POST /api/v1/whatsapp/webhook` → valida `X-Hub-Signature-256` (HMAC sha256 con app secret),
  responde `200` en <500ms y **encola** el payload crudo. No procesa inline.
**AC:**
- Firma inválida → `401`, no encola.
- Verificación GET con token correcto devuelve el challenge en texto plano.

### HU-03 · Cola Redis `whatsapp` + `IngestJob` idempotente — 1d `[BACK · REDIS]`
Cola dedicada `whatsapp` (connection Redis). `IngestJob` parsea `entry→changes→value`:
- Rama `messages[]` → crea/actualiza conversación + mensaje entrante.
- Rama `statuses[]` → se maneja en Sprint 2 (dejar hook).
**AC:**
- Reprocesar el mismo `wa_message_id` **no** duplica (upsert por `wa_message_id`).
- Worker `php artisan queue:work redis --queue=whatsapp` procesa el job.

### HU-04 · Resolución `phone → Person` + bandeja "Sin asignar" — 1.5d `[BACK]`
- Normalizar teléfonos a **E.164** (helper) y hacer match contra `Person.contact_numbers`.
- Backfill: comando artisan que normaliza los `contact_numbers` existentes.
- Si no hay Person → conversación con `status=unassigned`, `person_id=null`.
**AC:**
- Número conocido enlaza la conversación al Person (y a su lead activo si existe → `lead_id`).
- Número desconocido crea conversación `unassigned` sin romper el flujo.
- El comando de backfill es idempotente.

## Dependencias
- Credenciales Cloud API en `.env`: `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_TOKEN`,
  `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`.
- Suscribir el webhook en el panel de Meta apuntando a `.../api/v1/whatsapp/webhook`.

## Definición de Hecho del sprint
Un `curl` simulando el payload de Meta ("Received Text Message" de la colección) crea la
conversación + mensaje y lo asocia al Person correcto. Verificado con `php artisan tinker` / test.
