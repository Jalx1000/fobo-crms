---
name: project-dropbox-webhook
description: Webhook Dropbox fase 06 — controlador, job, comando init-cursor, tabla od_settings, rutas y CSRF
metadata:
  type: project
---

Implementado el webhook de Dropbox (fase 06) para recibir notificaciones en tiempo real de cambios en la carpeta SMD.

**Why:** Dropbox notifica via POST cuando hay cambios en la carpeta; el scheduler existente (smd:sync-dropbox) se mantiene como fallback.

**How to apply:** Cuando se trabaje sobre el flujo Dropbox→CRM, tener en cuenta que hay dos vías: webhook (tiempo real) y scheduler (fallback).

## Archivos creados/modificados

- `config/smd.php` — agregado `app_secret` a sección `dropbox`
- `packages/Webkul/Admin/src/Services/DropboxService.php` — agregados `getInitialCursor()` y `listChanges()`
- `packages/Webkul/Admin/src/Jobs/ProcessDropboxNotification.php` — job nuevo, lee cursor de `settings`, procesa entradas nuevas/actualizadas/canceladas
- `packages/Webkul/Admin/src/Http/Controllers/DropboxWebhookController.php` — GET verify (handshake), POST handle (valida HMAC sha256)
- `packages/Webkul/Admin/src/Console/Commands/InitDropboxCursor.php` — comando `smd:dropbox-init-cursor`, guarda cursor inicial en tabla `settings`
- `routes/api.php` — rutas GET+POST `/api/webhooks/dropbox`
- `app/Http/Middleware/VerifyCsrfToken.php` — excepción `api/webhooks/dropbox`
- `packages/Webkul/Admin/src/Providers/AdminServiceProvider.php` — registrado `InitDropboxCursor::class`
- `database/migrations/2026_05_20_000000_create_od_settings_table.php` — tabla `settings` (MySQL: `od_settings` por DB_PREFIX=od_)

## Tabla od_settings

Clave-valor genérica. `DB::table('settings')` con `DB_PREFIX=od_` genera `od_settings` en MySQL. La tabla NO usa el nombre con prefijo explícito en el código — igual que `smd_synced_events`.

## Flujo webhook

1. Dropbox hace GET con `?challenge=xxx` → controller responde el mismo texto (handshake)
2. Dropbox hace POST con firma HMAC en `X-Dropbox-Signature` → controller valida y dispatch `ProcessDropboxNotification`
3. Job lee cursor de `od_settings`, llama `listChanges()`, procesa archivos JSON nuevos/modificados, actualiza cursor

## Prerequisito de arranque

Ejecutar una vez: `php artisan smd:dropbox-init-cursor` para guardar el cursor inicial antes de registrar el webhook en el panel de Dropbox.
