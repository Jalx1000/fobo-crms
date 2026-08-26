# TODO — CRM inmobiliario Century 21

**Spec:** `docs/specs/crm-inmobiliario-c21.md` · **Plan:** `tasks/plan-crm-inmobiliario.md`
**Estado: ejecutado el 2026-08-25.** Solo escrituras en DB. Cero código. Cero n8n.

- [x] **00 · Backup completo de `kohlberg`**
      → `/tmp/kohlberg-pre-inmobiliaria-20260825-1230.sql` · 737 KB · 67 tablas · `kl_leads` presente

- [x] **01 · Renombrar las 4 etiquetas nativas de lead**
      → Título del lead · Descripción · Valor / comisión esperada · Fecha estimada de cierre
      → `database/sql/inmobiliaria/01-renombrar-etiquetas.sql`

- [x] **02 · Crear pipeline `Inmobiliaria C21` con sus 7 etapas**
      → `id = 14`, `rotten_days = 100`, 7 etapas con `won`/`lost` en `sort_order` 6 y 7
      → `database/sql/inmobiliaria/02-pipeline-inmobiliaria.sql`

- [x] **03 · Migrar los 20 leads y borrar los 7 pipelines de imprenta** ⚠️ alcance revisado
      Decisión del usuario: **un solo pipeline**, no dos. Los leads de imprenta se movieron al
      embudo inmobiliario en vez de quedarse en uno propio.
      → 03a migración: NO ATENIDOS→Prospectos (19) · ENTREGADOS→Ganado (1)
      → aserción `COUNT(*) = 0` de leads fuera del pipeline 14 antes de borrar
      → 03b borrado de los 7 pipelines (`ON DELETE CASCADE` neutralizado por la aserción)
      → `database/sql/inmobiliaria/03-migrar-leads.sql`, `03b-borrar-pipelines-viejos.sql`

- [x] **04 · Crear los 23 atributos custom y sus opciones**
      → 23 atributos · 64 opciones · `perdida_motivo` con 12 · 0 requeridos · 0 únicos
      → `database/sql/inmobiliaria/04-atributos.sql`
      → Recuento corregido: son 23, no 24 (12 `op_` + 5 `bot_` + 2 `vis_` + 1 `perdida_` + 3 `cap_`)

- [x] **05 · Agregar sources y types inmobiliarios**
      → 13 sources · 7 types · ninguna fila existente modificada
      → `database/sql/inmobiliaria/05-sources-types.sql`

- [x] **06 · Pasar el default al embudo inmobiliario**
      → aplicado dentro de 03a, antes del borrado, para no quedar sin default

- [x] **07 · Verificación SQL**
      → 1 pipeline · 7 etapas · 20 leads · 523 actividades · 5 cotizaciones · 21 personas
      · 15 productos · 0 leads sin etapa · 0 etapas cruzadas · 2 workflows (los originales)
      → cachés de Laravel limpiadas (`cache:clear`, `config:clear`, `view:clear`)

- [x] **08 · Escribir el rollback**
      → `database/sql/inmobiliaria/99-rollback.sql` revierte 01, 04 y 05.
      02/03/06 solo se revierten restaurando el dump de la tarea 00.

---

## Incidente durante la ejecución

El contenedor `heaven_imprimir` (MySQL 9.7.2) murió con **exit 137 (OOM)** mientras corría la
tarea 04. Levantó solo, la recuperación XA de InnoDB terminó limpia y no hubo pérdida de datos.
**No lo causó este trabajo:** el mismo contenedor registra exit 137 hace 20 horas, 2 días y
13 días. La tarea 04 se reaplicó sin problema por ser idempotente.
→ Vale la pena revisar el límite de memoria del servicio en EasyPanel.

## Pendiente de verificación manual en la UI

- [ ] El kanban muestra las 7 columnas en orden
- [ ] Crear un lead de prueba, moverlo a Ganado, confirmar `closed_at` no nulo, borrarlo
- [ ] El formulario de lead muestra los 23 atributos agrupados por prefijo, ninguno bloqueando
- [ ] Un lead viejo se abre, edita y guarda sin pedir campos nuevos

## Fuera de este lote

- Los 7 workflows con `trigger_webhook` — diseñados en el spec, no implementados (sin n8n)
- Asignación automática de `user_id` a Danitza Cuellar (`kl_users.id = 2`): requiere workflow
- Los 20 leads migrados conservan su `user_id` original de imprenta
