# Spec: Permiso de rol para cambiar la etapa de un lead

Estado: **implementado** — pendiente de verificación en el navegador y de correr la migración
Rama: `kohlberg`
Fecha: 2026-08-25

## Objetivo

Hoy **ningún rol restringe el cambio de etapa de un lead**. La ruta
`admin.leads.stage.update` no está declarada en `packages/Webkul/Admin/src/Config/acl.php`,
y el middleware `Webkul\Admin\Http\Middleware\Bouncer` sólo valida rutas presentes en ese
mapa (`Bouncer.php:81`, `if (isset($roles[Route::currentRouteName()]))`). Resultado: cualquier
usuario autenticado —incluso con un rol custom que sólo tenga `leads.view`— puede mover un
lead de etapa, tanto arrastrando en el tablero como desde la barra de etapas del lead.

Queremos un permiso nuevo, marcable por rol, que controle **cualquier** cambio de etapa.

**Usuario:** los 8 asesores (rol `Encargado`) y futuros roles custom. El rol admin
(`permission_type = 'all'`) conserva todo sin cambios.

**Éxito:** un rol custom sin el permiso no puede cambiar la etapa de un lead por ninguna vía,
ni desde la UI ni por HTTP directo.

## Inventario de vías de cambio de etapa

Relevado sobre el código actual. Las cuatro deben quedar cubiertas.

| # | Vía | Ruta | ACL hoy | Después |
|---|-----|------|---------|---------|
| 1 | Drag en el kanban (`kanban.blade.php:77`) | `admin.leads.stage.update` | **ninguno** | `leads.stage_update` |
| 2 | Barra de etapas en la vista del lead (`view/stages.blade.php:247`) | `admin.leads.stage.update` | **ninguno** | `leads.stage_update` |
| 3 | Mass update desde el DataGrid (`LeadController::massUpdate`, línea 736) | `admin.leads.mass_update` | `leads.edit` | `leads.stage_update` |
| 4 | Form de edición del lead (`edit.blade.php:48`, input oculto) | `admin.leads.update` | `leads.edit` | se ignora el campo sin permiso |

Notas sobre los casos 3 y 4:

- **#3** `massUpdate` sólo escribe `lead_pipeline_stage_id` (línea 746); no hace otra cosa.
  Por eso pasa a exigir el permiso nuevo en lugar de `leads.edit`.
- **#4** El form de edición manda `lead_pipeline_stage_id` en un input oculto con el valor
  **actual** del lead, y excluye el campo de los atributos editables (`edit.blade.php:110`).
  No es una vía de UI, pero el backend acepta el valor que llegue. Sin permiso, el campo se
  **ignora** (se fuerza al valor actual) en vez de rechazar el request: rechazar rompería la
  edición normal del lead, que siempre manda ese input.

## Tech Stack

Sin dependencias nuevas. Laravel 10 / Krayin, Blade + Vue 3 (vuedraggable), Pest PHP.

## Commands

```bash
php artisan test tests/Feature/LeadStagePermissionTest.php
./vendor/bin/pint packages/Webkul/Admin/src
php artisan migrate
npm run build
```

## Archivos afectados

```
packages/Webkul/Admin/src/Config/acl.php                        → declara la key nueva
packages/Webkul/Admin/src/Http/Controllers/Lead/LeadController.php → guardas en massUpdate y update
packages/Webkul/Admin/src/Resources/views/leads/index/kanban.blade.php → revert + toast al 401
packages/Webkul/Admin/src/Resources/views/leads/view/stages.blade.php  → ocultar barra sin permiso
packages/Webkul/Admin/src/Resources/lang/es/app.php             → cadena del error
packages/Webkul/Admin/src/Resources/lang/en/app.php             → cadena del error
packages/Webkul/User/src/Database/Migrations/<ts>_add_stage_update_permission_to_roles.php → migración de datos
tests/Feature/LeadStagePermissionTest.php                       → cobertura
```

## Diseño

### 1. La key del permiso

```php
// packages/Webkul/Admin/src/Config/acl.php, después de 'leads.delete'
[
    'key'   => 'leads.stage_update',
    'name'  => 'admin::app.acl.stage-update',
    'route' => ['admin.leads.stage.update', 'admin.leads.mass_update'],
    'sort'  => 5,
],
```

Con eso el middleware la protege solo, y `Acl::prepareAclItems()` (`Core/src/Acl.php:83`)
genera el checkbox anidado bajo Leads en el formulario de rol sin tocar ninguna vista.

**Restricción de nombre:** la key NO puede contener `activities` ni `tags`. `Bouncer.php:88`
y `Admin/src/Bouncer.php:34` tienen un fallback que aprueba cualquier permiso con esas
palabras si el rol tiene algo de leads — la haría inútil. `stage_update` es seguro.

### 2. Comportamiento del kanban (sin permiso)

Decisión tomada: **el drag sigue habilitado**, el backend responde 401 y el front avisa.
Se agrega **revert**: la tarjeta vuelve a su columna original. Sin revert, la tarjeta queda
visualmente en la etapa nueva mientras la DB dice otra cosa y el asesor cree que movió el lead.

```
Asesor arrastra "Juan Pérez" de No atendido → Contactado
   │
   ├─ PUT /leads/stage/edit/12  ──→  401
   │
   ├─ toast rojo: "No tenés permiso para cambiar la etapa de un lead."
   └─ la tarjeta vuelve a "No atendido"
```

### 3. Barra de etapas en la vista del lead (sin permiso)

Se renderiza en modo lectura: muestra la etapa actual, sin click. `stages.blade.php:209`
(`openModal`) ya corta cuando la etapa es la misma; se le agrega la guarda de permiso.

### 4. Migración de datos

Los roles custom que hoy tienen `leads.edit` reciben `leads.stage_update`. Nadie pierde una
capacidad que ya usa: los asesores siguen moviendo leads sin que tengas que configurar nada.
`permissions` es un cast `array` sobre JSON (`User/src/Models/Role.php:24`), así que la
migración lee, agrega la key si falta, y reescribe. Debe ser **idempotente**.

## Dónde se valida cada vía

Ajuste sobre el diseño inicial, hecho durante la implementación: al declarar las rutas en
`acl.php`, el middleware `Bouncer` ya aborta con 401 **antes** de llegar al controller. Una
guarda extra en `updateStage()` o `massUpdate()` sería código muerto.

- **Vías 1, 2 y 3** → las cubre el middleware, sin tocar el controller.
- **Vía 4** → única que necesita lógica propia: `admin.leads.update` está legítimamente
  permitida por `leads.edit`, así que el middleware no puede distinguir. La guarda vive en
  `LeadController::update()` y fuerza la etapa al valor actual del lead.

Como el middleware aborta con un mensaje genérico (`'Esta acción no está autorizada'`), el
frontend muestra su propia cadena traducida cuando el status es 401, en lugar de repetir el
mensaje del servidor.

## Testing Strategy

Pest PHP, en `tests/Feature/`. **Los tests corren contra la DB de producción y no usan
`RefreshDatabase`** — hay que crear roles/usuarios de prueba y limpiarlos en el teardown,
nunca mutar los roles reales. La suite ya tiene 15 fallos preexistentes ajenos a esto.

Casos:

1. Rol `permission_type = 'all'` → puede mover etapa por las 4 vías.
2. Rol custom **con** `leads.stage_update` → puede mover etapa.
3. Rol custom **sin** el permiso → `PUT admin.leads.stage.update` responde 401 y
   `lead_pipeline_stage_id` **no cambió en la DB**.
4. Rol custom sin el permiso → `admin.leads.mass_update` responde 401, ningún lead cambió.
5. Rol custom sin el permiso pero **con** `leads.edit` → puede editar el lead (título,
   descripción) y la etapa queda intacta. *(regresión del caso #4 del inventario)*
6. La migración es idempotente: correrla dos veces no duplica la key en `permissions`.

## Boundaries

- **Siempre:** proteger la ruta en `acl.php` antes de tocar el Blade — ocultar el drag es UX,
  no seguridad; validar server-side en toda vía; correr Pint.
- **Preguntar antes:** cambiar el ACL de rutas fuera de leads; tocar el fallback de
  `activities`/`tags` en Bouncer (está mal, pero es otro problema); modificar roles reales.
- **Nunca:** commitear la migración sin probarla; borrar permisos existentes de un rol;
  confiar sólo en la guarda del frontend.

## Criterios de éxito

- [ ] El checkbox "Mover etapa" aparece bajo Leads en el form de rol, sin tocar ninguna vista.
- [ ] Rol custom sin el permiso: `PUT /leads/stage/edit/{id}` → 401, etapa sin cambios en DB.
- [ ] Rol custom sin el permiso: mass update de etapa → 401, ningún lead modificado.
- [ ] Rol custom sin el permiso pero con `leads.edit`: puede editar el lead; la etapa no cambia.
- [ ] En el kanban sin permiso: sale el toast y la tarjeta **vuelve** a su columna original.
- [ ] En la vista del lead sin permiso: la barra de etapas es de sólo lectura.
- [ ] Tras la migración, todo rol que tenía `leads.edit` tiene `leads.stage_update`.
- [ ] Rol admin (`all`): sin cambios de comportamiento en ninguna vía.
- [ ] Los 6 tests pasan; los 15 fallos preexistentes siguen siendo los mismos.

## Decisiones tomadas

1. **Nombre visible del permiso:** "Mover etapa" (es) / "Update stage" (en).
2. **Caso #4:** se ignora `lead_pipeline_stage_id` en silencio y se conserva la etapa actual.

## Pendiente

- [ ] Verificar en el navegador (checkbox en el form de rol, revert del drag, edición normal
      del pedido intacta).
- [ ] Correr `php artisan migrate` en producción.

La config **no** está cacheada en el contenedor, así que el permiso aparece sin necesidad de
`php artisan config:clear`.
