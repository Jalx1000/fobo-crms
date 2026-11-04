# Informe de implementación - Tablero / Dashboard

**Proyecto:** Krayin CRM (Kohlberg) · **Rama:** `kohlberg` · **Entorno:** producción (kohlberg.sofopolis.com)
**Fecha del informe:** 2026-06-26

---

## 1. Resumen ejecutivo

Se rediseñó el Tablero para que **todas las tarjetas concilien entre sí** y con la **vista por etapa (kanban)**, usando un léxico unificado y datos confiables de pedidos/productos. Los ejes del trabajo fueron:

1. **Filtros globales** (ciudad + fecha) compartidos entre Tablero y módulo de Pedidos.
2. **Conciliación de métricas** bajo dos definiciones unificadas: "Opción B" y "Significado B'".
3. **Valor del pedido confiable** (lead_value = suma de productos, con override controlado por rol).
4. **Card de Evolución** rediseñado y nuevos KPIs.
5. **Corrección de datos históricos** mal cargados.

---

## 2. Filtros globales (ciudad y fecha)

**Objetivo:** que al elegir una ciudad o un rango de fechas en el Tablero, el mismo filtro aplique en el módulo de Pedidos, y viceversa, de forma persistente.

- **Cookies compartidas, no encriptadas:** `global_pipeline_id` (ciudad) y `global_date_range` (`from|to`).
  - Registradas en `EncryptCookies::$except` para poder leerlas desde JS y servidor.
- **Tablero (JS/Vue):** `dashboard/index.blade.php` lee las cookies al iniciar, las usa como valor por defecto de los filtros y las re-escribe ante cada cambio (ciudad y fecha por separado, para no contaminar una con la otra).
- **Servidor (Tablero):** `DashboardController` aplica las cookies como **default del lado servidor** (`applyGlobalFilterDefaults()`), resolviendo el helper `Dashboard` de forma diferida para que el reporting lea el rango correcto.
- **Pedidos:** `LeadController` persiste/redirige según la ciudad y el rango global; el kanban filtra cada etapa por su fecha de evento.
- **Fecha:** el rango se maneja por **start/end explícito** (no presets fijos), tal como se solicitó.

**Bug corregido:** tarjetas en blanco al cambiar de módulo (condición de carrera entre la carga inicial de las tarjetas y el emit del filtro). Se resolvió haciendo de la cookie el default del servidor y eliminando el emit redundante.

---

## 3. Conciliación de métricas

### 3.1 "Opción B" - cada lead se cuenta en su etapa actual por su fecha de evento

Se introdujo una expresión SQL `stageEventDateExpr()` que asigna a cada lead la fecha relevante según su etapa:

| Etapa actual | Fecha de evento usada |
|---|---|
| Prospecto | `created_at` |
| Pedido confirmado | `COALESCE(confirmed_at, created_at)` |
| Entregado / Cancelado | `COALESCE(closed_at, created_at)` |

Esto hace que los conteos por período sean consistentes en todas las tarjetas (funnel, por usuario, por ciudad, evolución, KPIs).

### 3.2 "Significado B'" - definición de "Prospectos"

La métrica **Prospectos** = leads actualmente en etapa **Prospecto OR Pedido confirmado** (el pipeline abierto), contados por su fecha de evento. Implementado con `openStageIds()` (unión de IDs de Prospecto + Confirmado).

Se aplicó B' a: barra de Prospectos, KPI "Total de Prospectos", funnel, conteos por usuario y por ciudad/pipeline, y la serie de evolución "Prospectos".

**Excepción solicitada:** "Productos solicitados" se mantiene como **demanda total** (todas las cantidades de productos sin importar la etapa, por `created_at`).

### 3.3 Léxico unificado

Se alineó el vocabulario del Tablero con la vista por etapa:
- "Pedidos creados" → **Prospectos**
- Evolución: leyenda "Ventas" → **Pedidos entregados**
- Tabs de Evolución reordenados: **Pedidos entregados | Prospectos | Valor de ventas | Productos vendidos**

---

## 4. Valor del pedido (lead_value) confiable

**Regla implementada (Opción 3):**
- `lead_value` = **suma de productos por defecto**, recalculado al crear/actualizar.
- Editable **manualmente solo por roles Supervisores / Administrador** (validado por rol, sin emails hardcodeados), mediante el flag `lead_value_is_manual`.
- Si el lead **no tiene productos**, se **respeta el valor manual**.

**Cambios:**
- `LeadRepository`: `applyLeadValueRule()` + `userCanOverrideLeadValue()` (chequeo de rol).
- `Lead` model: `lead_value_is_manual` en `fillable` + cast.
- Migración: nueva columna booleana `lead_value_is_manual`.

**Backfill:** se corrigieron los datos históricos donde el `lead_value` no reflejaba la suma de productos.

---

## 5. Card de Evolución (rediseño)

- **Dos series comparables punto a punto:** período actual (línea sólida morada) vs período anterior real (línea punteada gris). Se eliminó la antigua línea plana de promedio.
- **Cifra destacada = total del período**, con promedio por día/semana/mes como secundario.
- **Subtítulo de contexto** con los rangos de fechas (actual vs anterior).
- **Leyendas con color** (corregido bug de Tailwind JIT usando `style` inline en vez de clases `bg-[#hex]`).
- Backend (`buildEvolutionPayload`) ahora expone `previous_data` alineada, totales, rangos y período.

**Bug corregido:** Evolución "Productos vendidos" mostraba 5 vs el pie 14. Causa: `generateTimeIntervals` anclaba el primer bucket a la fecha cruda, descartando la última semana. Se ancló al límite del período (semana/mes/año) en `Lead.php` y `Product.php`. Verificado: las tres vistas → 14.

---

## 6. Nuevos KPIs y layout

- **Nuevo KPI "Total productos vendidos (Entregados)"** - cantidad de productos en leads en etapa Entregado, por `closed_at`.
- **KPI "Total de Prospectos"** corregido: antes usaba `total_persons` (entidad personas) con una etiqueta heredada del rename Clientes→Prospectos, mostrando 18 en vez de 10. Ahora usa `total_leads` con B'.
- **Layout:** las 4 tarjetas KPI se pusieron en **una sola fila de 4 columnas** (`grid-cols-4`, responsive a 2 y 1 columnas).

---

## 7. Verificaciones realizadas

- Conciliación validada con **datos reales de producción** (día 23 y período 27-mar a 24-jun): A=13, B'=10, entregados=3, cancelados=4; 9 productos / valor 420 en el pedido entregado del día 23.
- Auditoría del flujo SQL de "Prospectos" con `DB::enableQueryLog` → retorna 10 (correcto).
- **Tests:** ejecutados en una base aislada (`kohlberg_test`) para no afectar producción. Los fallos observados son de entorno (FK truncation, Bouncer sin usuario autenticado), **ninguno derivado de estos cambios**; los tests de dashboard y de leads relevantes pasaron. Base de prueba eliminada al terminar.

---

## 8. Archivos principales modificados

**Backend / Reporting:**
- `packages/Webkul/Admin/src/Helpers/Reporting/Lead.php` - `stageEventDateExpr`, `openStageIds`, B', `buildEvolutionPayload`, anclaje de intervalos.
- `packages/Webkul/Admin/src/Helpers/Reporting/Product.php` - productos vendidos (entregados), evolución de unidades.
- `packages/Webkul/Admin/src/Helpers/Dashboard.php` - `total_products_sold`, período de evolución.
- `packages/Webkul/Admin/src/Http/Controllers/DashboardController.php` - defaults globales del servidor.
- `packages/Webkul/Admin/src/Http/Controllers/Lead/LeadController.php` - filtros globales ciudad/fecha + kanban.
- `packages/Webkul/Lead/src/Repositories/LeadRepository.php` - regla de lead_value.
- `packages/Webkul/Lead/src/Models/Lead.php` - flag `lead_value_is_manual`.
- `packages/Webkul/Lead/src/Database/Migrations/...add_lead_value_is_manual...` - nueva columna.
- `app/Http/Middleware/EncryptCookies.php` - cookies globales exentas.

**Frontend:**
- `dashboard/index.blade.php` - filtros globales Vue + cookies.
- `dashboard/index/over-all.blade.php` - KPIs (4 columnas, nuevos KPIs).
- `dashboard/index/evolution.blade.php` - rediseño dos series + tabs.
- `dashboard/index/total-leads.blade.php`, `total-leads-vs-entregados.blade.php` - colores de leyenda.
- `lang/es/app.php` y `lang/en/app.php` - léxico unificado.

---

## 9. Pendiente / opcional

- **Descripción por tarjeta (subtítulo)** para mejorar UX - pendiente de definir el **término** para "Prospecto + Pedido confirmado" (se descartó "Prospectos abiertos").
- (Opcional) Aplicar B' a tarjetas hoy comentadas si se reactivan (ingresos por fuentes/tipos, funnel viejo, vendedores, leads por sucursal).
- (Opcional) Endurecer el group-key semanal (`%Y-%u` SQL vs `Y-W` PHP).
