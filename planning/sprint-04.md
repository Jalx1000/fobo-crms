# Sprint 4 — Hardening: tests, rate-limit, idempotencia, extras

**Duración:** 1 semana (~5 días-dev) · **Objetivo:** dejar el inbox listo para producción:
robustez, seguridad y cobertura de tests. Extras de UX opcionales.

## Historias

### HU-17 · Robustez del webhook — 1.5d `[BACK · REDIS]`
- Rate-limiting del endpoint de webhook.
- Reintentos con backoff exponencial en `IngestJob`, `SendJob` y webhook saliente.
- Idempotencia reforzada por `wa_message_id` (lock Redis para procesamiento concurrente).
- Dead-letter / log de payloads no parseables.
**AC:** duplicados y ráfagas de Meta no generan mensajes repetidos ni envíos dobles.

### HU-18 · Cobertura de tests Pest — 2d `[QA]`
Tests Feature/Unit para: verificación del webhook, validación de firma, ingesta (texto + media),
resolución `phone→Person`, envío (`SendJob` con Cloud API mockeado), switch IA (global vs
override) y auth por API key.
**AC:** `php artisan test` verde; caminos de error cubiertos (firma inválida, número desconocido,
IA off, fallo de Cloud API).

### HU-19 · Extras de UX y operación — 1.5d `[FRONT · BACK]`
- Bandeja "Sin asignar": listado de conversaciones sin Person + botón "Crear lead/person".
- Indicadores de typing/presence (opcional, vía Reverb).
- Contador de no leídos por conversación + métricas de mensajes fallidos.
**AC:** una conversación de número desconocido es visible y convertible a lead desde la bandeja.

## Post-MVP (backlog futuro, fuera de estas 5 semanas)
- Envío de video / sticker / ubicación / contacto.
- Plantillas (templates) de Meta para iniciar fuera de la ventana de 24h.
- Migración del histórico legacy de n8n a las tablas nuevas (si algún día se decide).

## Dependencias
- Sprints 0–3.

## Definición de Hecho del sprint
Suite de tests verde, webhook resistente a duplicados/ráfagas, y la bandeja "Sin asignar"
operativa. Feature apta para producción.
