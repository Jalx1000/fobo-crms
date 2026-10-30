# Plan: corregir "tablero a medias" - leads de la sync asignados al admin

> Estado: **IMPLEMENTADO Y APLICADO en producción (2026-06-29)**.
> Backfill ejecutado: 278 leads reasignados a "Sin asignar" (rol Sistema id 18,
> usuario id 57), auditados en `lead_reassignment_log`, reversibles con
> `php artisan leads:reassign-synced-to-unassigned --rollback --apply`.
> Fecha diagnóstico: 2026-06-29. Verificado con datos reales en el contenedor
> de producción `heaven_odontoking-crm`.

## Diagnóstico

**Síntoma:** en el rango 23–29 jun el tablero "carga a medias": unos cards muestran
datos y otros (los de vendedor) salen vacíos.

**Causa raíz (cadena completa, verificada con datos reales):**

1. En el rango hay **286 leads**. Los cards agregados (over-all, evolution, total-leads)
   muestran 286 ✅. Los cards por-vendedor (leads-by-users, ventas, tiempo-por-vendedor)
   muestran ~0 ❌.
2. **285 de 286 leads pertenecen a "Sofopolis" (rol Administrator).** Histórico total:
   285 Administrator vs 10 Recepcionista.
3. Los cards por-vendedor **excluyen a propósito** a los usuarios con rol `Admin%`
   (`packages/Webkul/Admin/src/Helpers/Reporting/Lead.php:78-83`, test
   `tests/Feature/DashboardExcludeAdminsTest.php`). → ocultan esos 285 leads.
4. **Por qué los leads quedan en el admin:** `LeadRepository::create`
   (`packages/Webkul/Lead/src/Repositories/LeadRepository.php:142-161`). Cuando un lead se
   crea sin `user_id` y sin usuario autenticado (caso de la sincronización SMD/Dropbox, que
   corre por comando/cron), el fallback es:
   `$userRepo->where('status',1)->orderBy('id')->first()` = **id 1 = Sofopolis (Admin)**.

Es el choque de dos reglas correctas por separado: "lead sin dueño → primer usuario activo"
+ "el tablero excluye admins".

**Distinción precisa sync vs manual (clave para el backfill):** existe la tabla puente
`smd_synced_events` (columnas `lead_id`, `external_id`, `source_file`, ...). Cruzando contra
ella, de los 285 leads del admin: **278 vienen de la sync** y **7 son manuales** (creados
de verdad por un admin logueado). Por eso el backfill debe apuntar a `smd_synced_events`,
no a "todo lead de un admin".

## Decisiones tomadas

1. Las citas de la sync deben pertenecer a una **cuenta "Sin asignar"** con **rol "Sistema"**
   (rol nuevo, no-admin, distinto de "Recepcionista").
2. Los históricos se reasignan con un **comando artisan** (no migración automática), y la
   **ejecución queda registrada/auditada**.
3. Para distinguir sync de manual se usa la tabla **`smd_synced_events`** (no se agrega campo nuevo).

---

## Plan de implementación

### Etapa 0 - Rol "Sistema" + cuenta "Sin asignar"
- Crear **rol "Sistema"**: no-admin, distinto de "Recepcionista" (para no inflar
  `tiempo-por-vendedor`, que filtra exactamente `Recepcionista` en `Lead.php:1218-1219`).
- Crear **usuario "Sin asignar"** con rol "Sistema", `status = 1`, email estable
  (p.ej. `sin-asignar@sistema.local`).
- Identificación en código por **email/config** (`config('dashboard.unassigned_user_email')`),
  nunca por id hardcodeado.
- Implementación: seeder idempotente + migración que cree rol y usuario si no existen.

### Etapa A - Raíz (creación de leads)
- Archivo: `packages/Webkul/Lead/src/Repositories/LeadRepository.php` (~142-161).
- Cambiar el fallback: sin `user_id` ni usuario autenticado → asignar a la cuenta
  "Sin asignar" (buscada por email/config), **nunca** a `orderBy('id')->first()`.
- Conservar intacto el caso con usuario autenticado (asigna al usuario real).
- Salvaguarda: si la cuenta "Sin asignar" no existe en algún entorno, dejar `user_id = null`
  (no caer al admin).

### Etapa B - Backfill de históricos (comando artisan auditado)
- Nuevo comando: `php artisan leads:reassign-synced-to-unassigned` con flags:
  - `--dry-run` (por defecto): solo reporta cuántos y cuáles leads movería.
  - `--apply`: ejecuta la reasignación.
- **Criterio quirúrgico:** leads que (a) están en `smd_synced_events` (`lead_id`) y
  (b) hoy tienen dueño con rol `Admin%` → reasignar a "Sin asignar". (≈278 leads).
  Los 7 leads manuales del admin **no se tocan**.
- **Registro/auditoría (decisión 2):**
  - Loguear en `storage/logs` (canal Laravel) inicio, criterio, conteo y fin.
  - Guardar un registro persistente del cambio (tabla de auditoría o columna que conserve
    el `old_user_id`), para poder revertir y para trazabilidad. Opción simple: tabla
    `lead_reassignment_log (lead_id, old_user_id, new_user_id, reason, created_at)`.
- Reversibilidad: con el log anterior, un `--rollback` puede restaurar `old_user_id`.

### Etapa C - Visibilidad en reporting
- Con los leads ya en "Sin asignar" (rol "Sistema", no-admin), aparecen solos en
  leads-by-users / ventas. Verificar que la exclusión `Admin%` sigue excluyendo solo a
  admins reales y NO a "Sin asignar".
- Opcional UI: ordenar/etiquetar el bucket "Sin asignar" para que sea claro.

### Tests (Pest)
- `LeadRepository`: lead sin auth y sin user_id → queda en "Sin asignar", no en admin.
- `LeadRepository`: lead con usuario autenticado → queda en ese usuario (sin regresión).
- Comando backfill `--dry-run`: cuenta correcta, no modifica nada.
- Comando backfill `--apply`: reasigna solo los leads de `smd_synced_events` con dueño admin;
  deja intactos los leads manuales del admin; escribe el log de auditoría.
- Dashboard: para un rango con leads sync, `leads-by-users` incluye "Sin asignar" con el
  conteo correcto; `DashboardExcludeAdminsTest` sigue verde (admins reales siguen excluidos).

### Orden de despliegue
1. Etapa 0 (rol + cuenta) → 2. Etapa A (creación) → 3. Etapa B (`--dry-run`, revisar, luego
   `--apply`) → 4. Etapa C (verificación). Así, al correr el backfill la cuenta ya existe y la
   creación nueva ya no reintroduce el problema.

## Referencias de código
- `packages/Webkul/Lead/src/Repositories/LeadRepository.php:142-161` - fallback de owner.
- `packages/Webkul/Admin/src/Helpers/Reporting/Lead.php:78-83` - exclusión de admins.
- `packages/Webkul/Admin/src/Helpers/Reporting/Lead.php:1218-1219` - filtro `Recepcionista`.
- `packages/Webkul/Admin/src/Services/SmdAppointmentSyncService.php` - escribe `smd_synced_events`.
- `tests/Feature/DashboardExcludeAdminsTest.php` - regla de exclusión de admins.
