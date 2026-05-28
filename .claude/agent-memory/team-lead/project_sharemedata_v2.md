---
name: sharemedata-v2-integration-plan
description: Plan de integración ShareMeData API v2 — brechas identificadas, endpoints nuevos de patients, riesgos críticos de base URL y schema Dropbox
metadata:
  type: project
---

El plan de integración ShareMeData API v2 fue creado el 2026-05-19 en `planning/00-sharemedata-api-v2/00.agent-task.md`.

**TODO YA IMPLEMENTADO (verificado 2026-05-26):**
- `GET /api/patients` — `ShareMeDataService::findPatientByPhone/Email/Name()` con `$patientsBaseUrl`
- `POST /api/patients/user` — `ShareMeDataService::createPatient()`
- `PATCH /api/patients/{id}` — `ShareMeDataService::updatePatient()`
- `GET /api/patients/{id}` — `ShareMeDataService::getPatient()`
- `$patientsBaseUrl` = `config('services.sharemedata.patients_url', 'https://gamma.sharemedata.com/api')` — variable separada ya resuelta
- Sincronización Dropbox: `DropboxService`, `DropboxWebhookController`, `ProcessDropboxNotification`, `SyncDropboxAppointments`, `InitDropboxCursor`
- `IncomingAppointmentService` — procesa citas entrantes desde Dropbox

**Lo que ya estaba integrado antes:**
- `GET /api/calendar/specialties` → `getSpecialties()` / `syncSpecialties()`
- `GET /api/calendar/schedule/availability` → `checkAvailability()`
- `POST /api/calendar/schedule/createEvent` → `createEvent()`
- Webhook entrante en `ShareMeDataWebhookController`

**Acción externa pendiente:** Que SMD añada la cuenta Dropbox del proyecto a su carpeta compartida (para activar la Fase 3 en producción).

**Why:** Se detectaron estas brechas al analizar el PDF v2 vs el código existente en `ShareMeDataService.php`, `AppointmentService.php` y `ShareMeDataWebhookController.php`.

**How to apply:** Al trabajar en cualquier tarea relacionada con ShareMeData, consultar primero el plan en `planning/00-sharemedata-api-v2/00.agent-task.md` para no duplicar trabajo ni ignorar brechas ya identificadas.
