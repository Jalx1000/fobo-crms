---
name: project-dropbox-sync
description: Implementación de sincronización de citas desde Dropbox (fase 05). Tabla smd_synced_events, comando smd:sync-dropbox, servicios DropboxService/SmdStatusMapper, actualización de IncomingAppointmentService.
metadata:
  type: project
---

Fase 05 completada: sincronización de citas desde Dropbox al CRM.

**Why:** SMD escribe archivos JSON en Dropbox por día. El CRM debe leerlos periódicamente para crear/actualizar/cancelar Leads y Activities.

**How to apply:** El scheduler corre `smd:sync-dropbox --days=2` cada 5 minutos. La tabla `od_smd_synced_events` (prefijo od_) registra qué `_id` SMD ya fue procesado y el hash del payload para detectar cambios.

## Archivos creados/modificados

- `database/migrations/2026_05_20_142014_create_smd_synced_events_table.php` — tabla de deduplicación
- `config/smd.php` — stage_map con IDs de pipeline (confirmed=7, completed=5, cancelled=6, default=1)
- `packages/Webkul/Admin/src/Services/DropboxService.php` — lista y descarga archivos JSON de Dropbox
- `packages/Webkul/Admin/src/Services/SmdStatusMapper.php` — mapea status SMD → lead_pipeline_stage_id
- `packages/Webkul/Admin/src/Services/IncomingAppointmentService.php` — reescrito con normalizeDropbox(), updateDropbox(), cancelDropbox(), bug fix attendances
- `packages/Webkul/Admin/src/Console/Commands/SyncDropboxAppointments.php` — comando Artisan
- `packages/Webkul/Admin/src/Providers/AdminServiceProvider.php` — registra el comando
- `app/Console/Kernel.php` — schedule everyFiveMinutes

## Hallazgos importantes

- Las tablas usan prefijo `od_` (DB_PREFIX=od_) pero en código Eloquent se usa el nombre sin prefijo (`tags`, `lead_tags`)
- El campo de stage en leads es `lead_pipeline_stage_id` (NO `lead_stage_id`)
- `Tag` model requiere `user_id` en fillable — `attachTagToLead()` usa user_id=1
- `owner` en JSON SMD puede ser string (solo _id) en eventos cancelados — `parseOwner()` lo maneja
- Bug original: `$data['attendances'][0]['patient']` — corregido a `$data['attendances'][0]`
- La tabla `tags` NO tiene columna `code`, por eso `SmdStatusMapper::toLeadStageId()` usa IDs directos del config
