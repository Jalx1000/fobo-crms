# Prompt para ejecutar el plan del tablero (leads sin asignar)

> Pegar en una nueva sesión o pasar a un subagente backend.
> Plan completo: `docs/plan-tablero-leads-sin-asignar.md`.

```
Implementa el plan documentado en docs/plan-tablero-leads-sin-asignar.md.

CONTEXTO (verificado con datos reales en el contenedor heaven_odontoking-crm):
El tablero "carga a medias" porque ~278 de los leads provienen de la sync SMD/Dropbox
y quedan a nombre del admin "Sofopolis" (rol Administrator). Los cards por-vendedor
(leads-by-users, ventas, tiempo-por-vendedor) excluyen a propósito a usuarios con rol
Admin% (Lead.php:78-83), así que esos leads quedan invisibles. La raíz está en
LeadRepository::create (LeadRepository.php:142-161): cuando un lead se crea sin user_id
y sin usuario autenticado, cae al primer usuario activo (= admin id 1). Los leads de la
sync se identifican con precisión por la tabla puente smd_synced_events (columna lead_id):
278 son sync, 7 son leads manuales reales del admin (no tocar esos 7).

DECISIONES YA TOMADAS (no volver a preguntar):
1. Crear un rol "Sistema" (no-admin, distinto de "Recepcionista") y un usuario
   "Sin asignar" con ese rol. Identificarlo por email/config, NUNCA por id hardcodeado.
2. El backfill es un COMANDO ARTISAN auditado, no una migración automática.
3. Distinguir sync vs manual con smd_synced_events (no agregar campos nuevos a leads).

IMPLEMENTA EN ESTE ORDEN, validando cada etapa antes de seguir:

Etapa 0 - Rol "Sistema" + cuenta "Sin asignar":
- Migración/seeder idempotente que cree el rol "Sistema" (permisos mínimos, no-admin) y
  el usuario "Sin asignar" (status=1, email estable p.ej. sin-asignar@sistema.local) si no existen.
- Agregar config('dashboard.unassigned_user_email') para resolver la cuenta.

Etapa A - Raíz (creación):
- En LeadRepository::create (~142-161), cambiar el fallback: sin user_id ni usuario
  autenticado → asignar al usuario "Sin asignar" (por email/config), nunca a orderBy('id')->first().
- Si la cuenta no existe en el entorno, dejar user_id = null (no caer al admin).
- No alterar el caso con usuario autenticado.

Etapa B - Backfill auditado:
- Comando: php artisan leads:reassign-synced-to-unassigned con --dry-run (default) y --apply.
- Criterio quirúrgico: leads que están en smd_synced_events (lead_id) Y hoy tienen dueño
  con rol Admin% → reasignar a "Sin asignar". NO tocar los 7 leads manuales del admin.
- Registrar la operación: log en canal Laravel + tabla de auditoría lead_reassignment_log
  (lead_id, old_user_id, new_user_id, reason, created_at) para trazabilidad y --rollback.
- --dry-run no modifica nada, solo reporta conteo y muestra los lead_id afectados.

Etapa C - Verificación reporting:
- Confirmar que con los leads ya en "Sin asignar" (no-admin) aparecen en leads-by-users/ventas
  y que la exclusión Admin% sigue excluyendo solo a admins reales.

TESTS (Pest, OBLIGATORIO - regla del proyecto):
- LeadRepository: lead sin auth y sin user_id → queda en "Sin asignar", no en admin.
- LeadRepository: lead con usuario autenticado → queda en ese usuario (sin regresión).
- Comando --dry-run: cuenta correcta, no modifica.
- Comando --apply: reasigna solo los leads de smd_synced_events con dueño admin, deja
  intactos los manuales, escribe lead_reassignment_log.
- Dashboard: leads-by-users incluye "Sin asignar" con conteo correcto;
  DashboardExcludeAdminsTest sigue verde.

RESTRICCIONES:
- Sigue las convenciones de Krayin/Concord (Proxies, repositories l5-repository) y el code
  style del proyecto (./vendor/bin/pint).
- La BD solo resuelve dentro del contenedor: ejecuta artisan/tinker con
  `docker exec -w /code heaven_odontoking-crm.1.v495q3yv5akel09j8f6sc7ls4 php artisan ...`.
- No corras --apply en producción sin antes mostrarme el resultado del --dry-run.
- No hagas commit ni push salvo que te lo pida.

Al terminar cada etapa, corre los tests y muéstrame el resultado verificado contra datos reales.
```
