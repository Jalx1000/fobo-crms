# Spec: CRM inmobiliario Century 21 Bolivia sobre la instancia `imprimir`

- **Estado:** borrador - pendiente de aprobación humana (Fase 1 de spec-driven development)
- **Fecha:** 2026-08-25
- **Instancia:** `https://imprimir.sofopolis.com` · DB `kohlberg` · prefijo `kl_` · contenedor `heaven_imprimir`
- **Documento origen:** `agente-inmobiliario-bolivia.md` + configuración de Sofo CRM aportada por el usuario
- **Cliente final:** asesor único de Century 21 Bolivia (https://c21.com.bo/)

---

## Objective

Reconfigurar la instancia Krayin de `imprimir` para que soporte la operación de venta,
alquiler y captación de inmuebles de **un asesor inmobiliario de Century 21**, sin perder
la operación de imprenta que hoy vive en la misma base.

Krayin queda como **sistema de registro**. La ejecución en tiempo real (catálogo con fotos y
geo, búsqueda y ranking de inmuebles, agenda con validación de choques, scoring, plantillas
HSM y recordatorios, transcripción de audios) vive en **n8n** y en la base propia del agente.
Esa frontera no se negocia: todo intento de meter esas piezas en Krayin exige tocar código.

**Éxito:** el asesor puede recibir un lead inmobiliario por WhatsApp, verlo calificado con
puntaje y temperatura, agendar y registrar una visita, y cerrarlo como ganado o perdido con
motivo estructurado - sin que nada de la operación de imprenta se rompa.

---

## Restricción dura

> **Solo se modifica la base de datos. No se toca una sola línea de código.**

Consecuencias que atraviesan todo el spec:

1. No se pueden agregar columnas, migraciones, seeders ni modelos.
2. Lo que Krayin no soporta a nivel de schema, **no se hace acá** - se delega a n8n.
3. El heurístico custom de won/lost en `packages/Webkul/Lead/src/Repositories/LeadRepository.php:234-235`
   se toma como **dado inmutable**, y el diseño de etapas se adapta a él.
4. Toda la configuración se aplica como SQL sobre `kohlberg`, o desde la UI de administración
   (que escribe en las mismas tablas). Se prefiere SQL por ser reproducible y auditable.

---

## Hallazgos de la instancia (verificación §11, ya ejecutada)

| # | Pregunta | Resultado |
|---|---|---|
| 1 | REST API instalado | ✅ `krayin/rest-api: ^2.1` |
| 2 | Workflows con acción webhook | ✅ `trigger_webhook` en `Automation/src/Helpers/Entity/Lead.php:93` |
| 3 | Atributos con scope por pipeline | ❌ No existe. `kl_attributes` solo discrimina por `entity_type` |
| 4 | Versión de Krayin | 2.x - `kl_lead_stages` vacía; etapas inline en `kl_lead_pipeline_stages` |
| 5 | Límite de usuarios | Sin límite. 6 usuarios activos, todos `@sofopolis.com` |

### Tres correcciones al documento origen

**C1 - Los rotting days son por pipeline, no por etapa.**
`rotten_days` es columna de `kl_lead_pipelines` (default 360 en los 7 pipelines actuales).
`kl_lead_pipeline_stages` solo tiene `code`, `name`, `probability`, `sort_order` y las dos FK.
Las tablas de rotting por etapa del documento origen **no son implementables sin tocar código**.
→ Decisión tomada: `rotten_days = 100` en todos los pipelines. El SLA fino por etapa lo calcula
n8n consultando `updated_at` vía REST API.

**C2 - Krayin NO agrega Won/Lost automáticamente.**
Son filas normales sembradas por `Installer/src/Database/Seeders/Lead/PipelineSeeder.php`
con `code='won'` / `code='lost'`. Los 7 pipelines de imprenta **no las tienen**: se apoyan en el
heurístico por nombre (`entregado`/`ganado` → won, `cancelado`/`perdido` → lost).
→ El embudo inmobiliario crea Ganado/Perdido explícitamente, con `code` **y** nombre compatibles.

**C3 - La instancia está viva y es de otro negocio.**
7 pipelines por ciudad · 20 leads · 21 personas · 4 organizaciones · 15 productos ·
523 actividades · 5 cotizaciones. Los atributos nativos de lead están renombrados a lenguaje
de imprenta (`title` = "Nombre de cliente", `lead_value` = "Valor del Pedido",
`expected_close_date` = "Fecha de Entrega Esperada").

**C4 - `lead_stage_id` ya no existe (verificado).**
El schema real de `kl_lead_pipeline_stages` es `id`, `code`, `name`, `probability`, `sort_order`,
`lead_pipeline_id`. La única FK activa es `lead_pipeline_id → kl_lead_pipelines`. La columna
`lead_stage_id` fue eliminada por una migración posterior, lo que explica que `kl_lead_stages`
esté vacía. → Crear etapas es un INSERT plano de 5 columnas, sin dependencias previas.

---

## Estado final deseado

### Pipelines: exactamente 1

| id | Nombre | is_default | rotten_days | Uso |
|---|---|---|---|---|
| 14 | Inmobiliaria C21 | 1 | 100 | Único embudo: venta, alquiler/anticrético y captación |

**Decisión revisada (2026-08-25).** El plan original contemplaba 2 pipelines (Imprenta +
Inmobiliaria). El usuario optó por consolidar en uno solo: los 20 leads de imprenta se migraron
al embudo inmobiliario y los 7 pipelines de ciudad (1 Santa Cruz, 4 Potosí, 6 Oruro, 7 La Paz,
8 Cochabamba, 9 Sucre, 10 Sin ciudad) se eliminaron.

Mapeo de etapas aplicado en la migración:

| Etapa de imprenta | → | Etapa inmobiliaria | Leads |
|---|---|---|---|
| NO ATENIDOS | → | Prospectos | 19 |
| ENTREGADOS | → | Ganado | 1 |

> **Información perdida a propósito (Q2):** la ciudad estaba codificada en el nombre del
> pipeline y no se preservó. 18 de los 20 leads eran de Santa Cruz.

### Etapas del embudo Inmobiliaria C21

Un solo embudo. La distinción venta / alquiler / captación vive en el atributo `op_modalidad`,
no en pipelines separados - para un asesor único, tres tableros medio vacíos cuestan más de lo
que informan.

| sort | code | name | probability |
|---|---|---|---|
| 1 | `prospectos` | Prospectos | 10 |
| 2 | `visita-agendada` | Visita agendada | 40 |
| 3 | `visita-realizada` | Visita realizada | 65 |
| 4 | `cliente-efectivo` | Cliente efectivo | 90 |
| 5 | `sin-interes` | Sin interés | 20 |
| 6 | `won` | Ganado | 100 |
| 7 | `lost` | Perdido | 0 |

Ganado y Perdido son las **únicas** etapas que cierran el lead: disparan `closed_at` por la rama
`$code === 'won'` / `$code === 'lost'` del heurístico, y además por nombre. Cliente efectivo y
Sin interés son etapas de trabajo, no de cierre - el asesor mueve la tarjeta una segunda vez
para cerrar.

### `lead_value` = comisión esperada (opción B del documento origen)

Venta: 3 % del precio. Alquiler: un mes. Anticrético: según política. Captación: comisión
estimada de la futura operación. Lo carga n8n al vincular el inmueble.

Motivo: es la única definición que hace que "valor del pipeline" sea plata facturable y que un
alquiler de 750 USD y una venta de 120.000 USD puedan convivir en el mismo forecast. El precio
del inmueble ya vive en el producto y en el catálogo propio; duplicarlo en el lead no aporta.

> **Pendiente de decisión operativa:** la etiqueta actual de `lead_value` es "Valor del Pedido"
> (imprenta). Renombrarla afecta a los dos pipelines por igual. Ver Q1.

### Renombre de atributos nativos

Las etiquetas actuales están en lenguaje de imprenta y las comparten los dos pipelines. Se pasan
a lenguaje neutro para que sirvan en ambos negocios:

| code | Etiqueta actual | Etiqueta nueva |
|---|---|---|
| `title` | Nombre de cliente | Título del lead |
| `description` | Descripción del pedido | Descripción |
| `lead_value` | Valor del Pedido | Valor / comisión esperada |
| `expected_close_date` | Fecha de Entrega Esperada | Fecha estimada de cierre |

`lead_source_id` ("Canal"), `lead_type_id` ("Tipo de cliente") y `user_id` ("Asesor Asignado") ya
son neutros y se dejan como están.

### Atributos a crear - 23, entidad `leads`

Los 23 (recuento verificado: 12 `op_` + 5 `bot_` + 2 `vis_` + 1 `perdida_` + 3 `cap_`).
Ninguno obligatorio (`is_required = 0`) y ninguno único (`is_unique = 0`). Ver Boundaries para
el porqué. Todos con `is_user_defined = 1` y `entity_type = 'leads'`.

Los tipos usados (`text`, `textarea`, `price`, `boolean`, `select`, `datetime`) están todos en el
selector de la UI (`settings/attributes/create.blade.php:336`), así que son válidos.

| sort | code | name | type | opciones |
|---|---|---|---|---|
| 100 | `op_modalidad` | Modalidad | select | venta · alquiler · anticrético · preventa |
| 101 | `op_tipo_inmueble` | Tipo de inmueble | select | departamento · casa · terreno · local · oficina · galpón · quinta · agrícola |
| 102 | `op_departamento` | Departamento | select | Beni · Chuquisaca · Cochabamba · La Paz · Oruro · Pando · Potosí · Santa Cruz · Tarija |
| 103 | `op_zona` | Zona | text | Normalizada por n8n con `GET_ZONAS` |
| 104 | `op_presupuesto_min` | Presupuesto mín. | price | |
| 105 | `op_presupuesto_max` | Presupuesto máx. | price | |
| 106 | `op_moneda` | Moneda | select | USD · BOB |
| 107 | `op_dormitorios` | Dormitorios mín. | select | indistinto · 1 · 2 · 3 · 4 · 5 o más |
| 108 | `op_forma_pago` | Forma de pago | select | contado · crédito bancario · crédito VIS · plan desarrollador |
| 109 | `op_banco` | Banco | text | |
| 110 | `op_uso` | Uso | select | vivir · inversión · renta · negocio |
| 111 | `op_plazo` | Plazo de decisión | select | ≤30 días · 1–3 meses · 3–6 meses · sin definir |
| 120 | `bot_puntaje` | Puntaje | text | Numérico, lo escribe n8n |
| 121 | `bot_temperatura` | Temperatura | select | caliente · tibio · frío |
| 122 | `bot_codigos_vistos` | Inmuebles mostrados | textarea | Códigos separados por coma |
| 123 | `bot_wa_id` | WhatsApp ID | text | **Clave de deduplicación** - ver Boundaries |
| 124 | `bot_pausado_hasta` | Bot pausado hasta | datetime | El bot no responde mientras esté vigente |
| 130 | `vis_codigo` | Inmueble de la visita | text | SKU del producto |
| 131 | `vis_resultado` | Resultado de la visita | select | le gustó · no le gustó · no asistió · pendiente |
| 140 | `perdida_motivo` | Motivo de pérdida | select | ver lista cerrada abajo |
| 150 | `cap_precio_esperado` | Precio esperado (captación) | price | |
| 151 | `cap_estado_documental` | Estado documental | select | folio real limpio · en regularización · solo minuta · preventa |
| 152 | `cap_exclusividad` | Exclusividad | boolean | |

Los `sort_order` altos (100+) mantienen los atributos nativos arriba en el formulario y agrupan
los custom por prefijo, para que el asesor lea el formulario por bloques.

**Lista cerrada de `perdida_motivo`:** Sin presupuesto real · No calificó al crédito ·
Compró o alquiló con otra inmobiliaria · Zona sin cartera · Precio fuera de mercado ·
No aceptó requisitos o garantía · Documentación trabada · Nunca respondió · Solo consultaba ·
No es el titular · No aceptó exclusividad · Duplicado

Reemplaza funcionalmente al campo nativo `lost_reason` (texto libre, no agrupable). `lost_reason`
se deja intacto en el schema - borrarlo sería tocar código.

### Sources y Types

**Sources** - a agregar a los 7 existentes (Correo Electrónico, Web, Formulario Web, Teléfono,
Directo, Whatsapp, Messenger): Anuncio Meta · Anuncio Google · Portal inmobiliario · Referido ·
Feria · Cartel en obra.

**Types** - a agregar a los 2 existentes (Nuevo Cliente, Cliente Antiguo): Comprador ·
Inquilino · Propietario · Inversionista · Referidor.

Ambas listas son compartidas por los dos pipelines (son tablas globales, no por pipeline).

### Workflows

Todos sobre `entity_type = 'leads'`. Las acciones disponibles en esta instancia son
`update_lead`, `update_person`, `send_email_to_person`, `send_email_to_sales_owner`, `add_tag`,
`add_note_as_activity` y `trigger_webhook`.

> **🚫 FUERA DE ALCANCE EN ESTA FASE.** Los 7 workflows del documento origen apuntan todos a
> webhooks de n8n, y la instrucción es no tocar n8n: solo se modifican datos del CRM. La tabla de
> abajo queda documentada como diseño objetivo, **no se implementa**.

| Evento | Condición | Acción | Requiere n8n |
|---|---|---|---|
| `lead.update.after` | `bot_temperatura` = caliente | `send_email_to_sales_owner` + `trigger_webhook` | parcial |
| `lead.update.after` | stage = Visita agendada | `trigger_webhook` → recordatorios 24 h y 2 h | sí |
| `lead.update.after` | stage = Visita realizada | `trigger_webhook` → `seguimiento_post_visita` a las 3 h | sí |
| `lead.update.after` | stage = Cliente efectivo | `trigger_webhook` → marcar inmueble reservado | sí |
| `lead.update.after` | stage = Ganado | `trigger_webhook` → vendido/alquilado + baja del producto | sí |
| `lead.update.after` | stage = Perdido | `trigger_webhook` → validar `perdida_motivo` + nurturing 45 d | sí |
| `lead.update.after` | `op_presupuesto_max` cargado | `trigger_webhook` → recalcular `lead_value` | sí |

El único parcialmente implementable sin n8n es el primero, reducido a `send_email_to_sales_owner`
sobre `bot_temperatura = caliente`. No se crea sin pedido explícito: mandaría correo real a
`dcuellar@sofopolis.com` cada vez que el atributo cambie.

El asesor de la operación inmobiliaria es **Danitza Cuellar** (`dcuellar@sofopolis.com`,
`kl_users.id = 2`). Es el `user_id` que llevan los leads del embudo inmobiliario.

El workflow de asignación por `op_departamento` del documento origen **no se crea**: con un solo
asesor no tiene destinatario.

La validación de `perdida_motivo` obligatorio se hace en n8n al recibir el webhook de Perdido,
**no** con `is_required` - ver Boundaries.

### Productos = inmuebles (espejo mínimo)

Cada inmueble se carga como producto con `SKU = código`, solo para poder enlazarlo al lead y
dejar rastro en el timeline. n8n crea/actualiza el producto al publicar y lo marca inactivo al
vender o alquilar. El catálogo real (fotos, geo, superficie, disponibilidad) vive fuera.

Los 15 productos de imprenta actuales conviven sin conflicto: se distinguen por SKU.

### Visitas = Activities tipo meeting

- `title`: `Visita SC-EQ-0142 - Equipetrol`
- `schedule_from` / `schedule_to`: horario con buffer ya aplicado
- `participants`: asesor asignado
- `comment`: punto de encuentro, acompañantes, notas
- Al cerrarla, el asesor carga `vis_resultado` en el lead

Krayin **no valida choques de horario** - Activities acepta solapamientos sin quejarse. Por eso
la agenda autoritativa vive en n8n y Krayin recibe el reflejo. Invertir ese orden hace que el
bot agende dos visitas en el mismo slot.

---

## Tech Stack

- Krayin CRM 2.x sobre Laravel 10 (arquitectura modular Concord, `packages/Webkul/`)
- MySQL 9 - DB `kohlberg`, prefijo de tablas `kl_`
- `krayin/rest-api ^2.1` para el tráfico n8n → Krayin
- n8n como sistema de ejecución (fuera de este repositorio)
- Contenedores Docker gestionados por EasyPanel

Tablas tocadas: `kl_lead_pipelines`, `kl_lead_pipeline_stages`, `kl_leads`, `kl_attributes`,
`kl_attribute_options`, `kl_lead_sources`, `kl_lead_types`, `kl_workflows`.

---

## Commands

```bash
# Variables de conexión (contenedor de MySQL)
cd /etc/easypanel/projects/heaven/imprimir_laravel/code
PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)
C=$(docker ps --format '{{.Names}}' | grep '^heaven_imprimir\.' | head -1)

# Backup completo ANTES de cualquier escritura (obligatorio, ver Boundaries)
docker exec "$C" mysqldump -ukohlberg -p"$PW" --single-transaction kohlberg \
  > /tmp/kohlberg-pre-inmobiliaria-$(date +%Y%m%d-%H%M).sql

# Consulta de verificación
docker exec "$C" mysql --default-character-set=utf8mb4 -ukohlberg -p"$PW" kohlberg -e "SELECT ..."

# Aplicar un script de migración
docker exec -i "$C" mysql --default-character-set=utf8mb4 -ukohlberg -p"$PW" kohlberg < script.sql

# Limpiar caché de Laravel tras cambios de configuración
docker exec heaven_imprimir_laravel.1.* php artisan cache:clear
```

---

## Project Structure

Los scripts SQL de esta configuración viven en:

```
docs/specs/crm-inmobiliario-c21.md    → este spec
tasks/plan-crm-inmobiliario.md        → plan técnico (Fase 2)
tasks/todo-crm-inmobiliario.md        → tareas ordenadas (Fase 3)
database/sql/inmobiliaria/            → scripts SQL numerados, uno por tarea
  01-backup-verify.sql
  02-pipeline-inmobiliaria.sql
  03-consolidar-imprenta.sql
  04-atributos.sql
  05-sources-types.sql
  99-rollback.sql
```

Ningún archivo bajo `packages/` se modifica.

---

## Code Style

SQL idempotente, transaccional y con el prefijo `kl_` siempre explícito. Cada script se puede
correr dos veces sin duplicar nada.

```sql
-- 02-pipeline-inmobiliaria.sql
START TRANSACTION;

INSERT INTO kl_lead_pipelines (name, is_default, rotten_days, created_at, updated_at)
SELECT 'Inmobiliaria C21', 1, 100, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM kl_lead_pipelines WHERE name = 'Inmobiliaria C21');

SET @pid := (SELECT id FROM kl_lead_pipelines WHERE name = 'Inmobiliaria C21');

INSERT INTO kl_lead_pipeline_stages (code, name, probability, sort_order, lead_pipeline_id)
SELECT * FROM (
    SELECT 'prospectos'       AS code, 'Prospectos'       AS name, 10  AS probability, 1 AS sort_order, @pid AS lead_pipeline_id
    UNION ALL SELECT 'visita-agendada',  'Visita agendada',  40, 2, @pid
    UNION ALL SELECT 'visita-realizada', 'Visita realizada', 65, 3, @pid
    UNION ALL SELECT 'cliente-efectivo', 'Cliente efectivo', 90, 4, @pid
    UNION ALL SELECT 'sin-interes',      'Sin interés',      20, 5, @pid
    UNION ALL SELECT 'won',              'Ganado',          100, 6, @pid
    UNION ALL SELECT 'lost',             'Perdido',           0, 7, @pid
) AS nuevas
WHERE NOT EXISTS (
    SELECT 1 FROM kl_lead_pipeline_stages s
    WHERE s.lead_pipeline_id = @pid AND s.code = nuevas.code
);

COMMIT;
```

Convenciones:

- Nombres de etapa y opciones **en español**, con tildes, tal como los ve el asesor.
- `code` en kebab-case sin tildes.
- Cada script abre con `START TRANSACTION` y cierra con `COMMIT`.
- Ningún `DELETE` sin un `SELECT` previo que muestre exactamente qué filas caen.
- No se escribe `lead_stage_id`: **esa columna ya no existe** en esta instancia (ver C4).

---

## Testing Strategy

No hay tests automatizados: no se toca código, así que Pest no aplica. La verificación es por
**consulta SQL de aserción** más una pasada manual por la UI.

Cada tarea del `todo` cierra con su query de verificación. Batería completa:

```sql
-- 2 pipelines, ambos con rotten_days = 100
SELECT id, name, is_default, rotten_days FROM kl_lead_pipelines;

-- 7 etapas en el embudo nuevo, en orden, con won/lost al final
SELECT sort_order, code, name, probability FROM kl_lead_pipeline_stages
WHERE lead_pipeline_id = @pid ORDER BY sort_order;

-- 0 leads huérfanos: todo lead apunta a una etapa de su propio pipeline
SELECT l.id FROM kl_leads l
JOIN kl_lead_pipeline_stages s ON s.id = l.lead_pipeline_stage_id
WHERE s.lead_pipeline_id <> l.lead_pipeline_id;

-- 24 atributos custom, ninguno requerido ni único
SELECT COUNT(*) total,
       SUM(is_required) requeridos,
       SUM(is_unique)   unicos
FROM kl_attributes WHERE entity_type='leads' AND is_user_defined=1;

-- Opciones cargadas por atributo select
SELECT a.code, COUNT(o.id) opciones
FROM kl_attributes a LEFT JOIN kl_attribute_options o ON o.attribute_id = a.id
WHERE a.entity_type='leads' AND a.type='select' AND a.is_user_defined=1
GROUP BY a.code ORDER BY a.code;

-- Los 20 leads de imprenta siguen existiendo y no perdieron actividades
SELECT COUNT(*) leads, (SELECT COUNT(*) FROM kl_activities) actividades FROM kl_leads;
```

Verificación manual obligatoria en la UI antes de dar por cerrada la Fase 4:

1. El kanban del embudo inmobiliario muestra las 7 columnas en orden.
2. Crear un lead de prueba en Prospectos, moverlo a Ganado, confirmar que `closed_at` se llenó.
3. El formulario de lead muestra los 24 atributos agrupados por prefijo, ninguno bloqueando el guardado.
4. Abrir un lead viejo de imprenta y guardarlo sin cambios - no debe pedir ningún campo nuevo.
5. Borrar el lead de prueba.

El punto 4 es la prueba de regresión que importa: es donde se rompería la operación existente.

---

## Boundaries

**Siempre:**
- Correr el `mysqldump` antes de la primera escritura de cada sesión.
- Envolver cada script en una transacción.
- Ejecutar el `SELECT` de inspección antes de cualquier `DELETE` o `UPDATE` masivo.
- Mantener `is_required = 0` en los 24 atributos nuevos. Los atributos son por entidad, no por
  pipeline: un solo atributo obligatorio rompe la carga de leads en el pipeline de imprenta.
- Mantener `is_unique = 0` en `bot_wa_id`. La deduplicación es lógica de negocio (un mismo
  celular genera un lead nuevo cuando el anterior ya está en Ganado o Perdido) y vive en n8n.
  Un índice único la haría imposible.
- Verificar cada tarea con su query antes de pasar a la siguiente.

**Preguntar primero:**
- Cualquier `DELETE` sobre `kl_leads`, `kl_persons`, `kl_activities` o `kl_quotes`.
- Renombrar atributos nativos (`title`, `lead_value`, `expected_close_date`) - afecta a los dos
  pipelines a la vez.
- Cambiar `is_default` del pipeline: mueve el destino de todo lead creado sin pipeline explícito.
- Borrar los 6 pipelines de ciudad.

**Nunca:**
- Modificar archivos bajo `packages/`, `app/`, `config/` o `database/migrations/`.
- Crear columnas o tablas nuevas.
- Correr `php artisan migrate:fresh`, `db:seed` o cualquier comando que reinicialice datos.
- Borrar `lost_reason` u otros campos nativos.
- Escribir en la DB sin backup previo de esa sesión.
- Commitear credenciales de la DB en ningún archivo del repo.

---

## Success Criteria

1. `kl_lead_pipelines` tiene exactamente 1 fila: `Inmobiliaria C21`, con `rotten_days = 100` e
   `is_default = 1`.
2. `Inmobiliaria C21` tiene 7 etapas en el orden y con las probabilidades de la tabla de arriba;
   `won` y `lost` son las de `sort_order` 6 y 7.
3. Un lead movido a Ganado o Perdido tiene `closed_at` no nulo; movido a cualquier otra etapa lo
   tiene nulo.
4. `kl_attributes` tiene 23 filas con `entity_type='leads'` e `is_user_defined=1`, todas con
   `is_required=0` e `is_unique=0`.
5. Cada atributo `select` tiene su lista completa de opciones en `kl_attribute_options`;
   `perdida_motivo` tiene exactamente 12.
6. `kl_lead_sources` tiene 13 filas y `kl_lead_types` 7.
7. `kl_workflows` sigue teniendo exactamente sus 2 filas originales de actividades - no se creó
   ni modificó ningún workflow.
8. **Regresión:** los 20 leads siguen existiendo, con sus 523 actividades, sus 5 cotizaciones,
   sus 21 personas y sus 15 productos; todos apuntan a una etapa válida del pipeline
   `Inmobiliaria C21`; y un lead viejo se puede abrir, editar y guardar sin que ningún campo
   nuevo lo bloquee.
9. Ningún archivo del repositorio fue modificado (`git status` limpio respecto de esta tarea,
   salvo los `.md` y `.sql` nuevos de documentación).

---

## Decisiones cerradas (ex Open Questions)

| | Pregunta | Resolución |
|---|---|---|
| Q1 | Etiquetas nativas | Renombrar a lenguaje neutro (ver tabla arriba) |
| Q2 | Ciudad de los leads de imprenta | **No se preserva.** Los 7 pipelines se borraron y la ciudad se descartó |
| Q3 | `op_dormitorios` | `select`: indistinto · 1 · 2 · 3 · 4 · 5 o más |
| Q4 | Usuario del asesor | Danitza Cuellar - `dcuellar@sofopolis.com`, `kl_users.id = 2` |
| Q5 | `lead_stage_id` | Resuelto por verificación: la columna no existe (ver C4) |
| Q6 | Webhooks de n8n | **No se toca n8n.** Los 7 workflows quedan fuera de alcance |

## Fuera de alcance (definitivamente en n8n o en la base propia)

- Catálogo de inmuebles con fotos, geo, superficie y disponibilidad en tiempo real
- Búsqueda y ranking de inmuebles por criterios del cliente (`BUSCAR_INMUEBLES`)
- Agenda con validación de choques y buffers por zona (`GET_DISPONIBILIDAD_VISITA`)
- Cálculo del puntaje y la temperatura del lead
- Envío de plantillas HSM y recordatorios de 24 h / 2 h
- Transcripción de audios
- Alertas de búsqueda guardada
- SLA fino por etapa (Krayin solo da un `rotten_days` por pipeline)
- **Los 7 workflows con `trigger_webhook`** - diseñados y documentados, no implementados (Q6)
