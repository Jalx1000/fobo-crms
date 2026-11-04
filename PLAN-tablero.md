# Planeación - Tablero, léxico unificado y valor de pedidos

> Branch: `kohlberg` · Entorno: **producción** (kohlberg.sofopolis.com) · DB: `kohlberg` (prefijo `kl_`)
> Documento de referencia de todo lo trabajado y lo que falta.

---

## 1. Objetivo

Hacer que **el tablero (dashboard) y la vista por etapa (kanban) cuadren entre sí**, usen
el **mismo léxico** y que el **valor monetario de los pedidos** sea confiable.

**Léxico oficial (igual en kanban y en todos los cards):**
`Prospecto` · `Pedido confirmado` · `Pedido entregado` · `Pedido cancelado`

---

## 2. Decisiones acordadas (el "por qué")

### 2.1 Opción B - fecha por evento de etapa
El problema raíz era que cada card contaba con una fecha distinta (`created_at`,
`confirmed_at`, `closed_at`) y la vista por etapa con otra, así que nada cuadraba.

**Regla única:** cada lead cuenta UNA vez, en su **etapa actual**, en la **fecha del
evento** de esa etapa:

| Etapa actual | Fecha que define "cuándo cuenta" |
|---|---|
| Prospecto | `created_at` (cuándo se creó) |
| Pedido confirmado | `confirmed_at` (cuándo se confirmó) |
| Pedido entregado | `closed_at` (cuándo se entregó) |
| Pedido cancelado | `closed_at` (cuándo se cerró) |

**Excepción - "Productos solicitados":** son TODAS las cantidades de productos, de
cualquier etapa, por `created_at`. Es **demanda** (lo que se pidió), no una etapa.
Por eso puede haber "9 vendidos y 0 solicitados" en un solo día: el pedido se **creó**
el 18 (ahí se solicitaron) y se **entregó** el 23 (ahí se vendieron). En un rango que
cubra ambos días, solicitados = vendidos.

### 2.2 lead_value (valor del pedido) - Opción 3
- Por defecto **automático**: `lead_value = Σ(precio × cantidad)` de los productos.
- **Override manual SOLO para Supervisor/Administrador** (rol).
- Si el lead **no tiene productos**, respeta el valor manual.
- Un **cambio de etapa** (mover en kanban) **no** recalcula ni toca el valor.
- Razón: un campo libre editable por cualquiera producía datos basura (ej. Lead #158
  con productos por Bs. 420 pero `lead_value = 0`), rompiendo el KPI "Valor de ventas".

---

## 3. HECHO y verificado

### 3.1 Valor de pedidos y productos (`LeadRepository`)
- **Bug corregido:** al **editar** un lead, el `amount` de cada producto no se
  recalculaba (sí al crear). Ahora `amount = precio × cantidad` en crear **y** editar.
  (Era la causa de que "Malbec 3×62" tuviera `amount = 0`.)
- **Regla lead_value** (`applyLeadValueRule`):
  - Solo actúa cuando el guardado trae productos (ignora cambios de etapa del kanban).
  - `lead_value = Σ productos` salvo override.
  - Override manual: requiere flag explícito `lead_value_is_manual` **y** rol
    Supervisor/Administrador (gateado en servidor → un asesor no puede sobrescribirlo
    aunque manipule el request).
- **Migración + modelo:** columna `lead_value_is_manual` (boolean) en `leads`.
- **Backfill ejecutado en producción:** 9 `amount` corregidos · 17 leads recalculados ·
  **Lead #158: 0 → Bs. 420**. (Los que ya tenían un valor manual ≠ suma se preservaron.)

### 3.2 Opción B aplicada (reconciliación)
- `Reporting/Lead.php`:
  - Nuevo conjunto `confirmedStageIds`.
  - Helper `stageEventDateExpr()` → expresión SQL CASE con la "fecha de evento por etapa"
    (con `COALESCE(..., created_at)` para filas viejas sin fecha).
- **Funnel** (`getOpenLeadsByStatesFixed`): pasó de `created_at` a la fecha de evento por
  etapa.
- **Kanban** (`LeadController::get()`): cada columna filtra por su propia fecha de evento
  (`match` por el `code` de la etapa). → El pedido entregado #158 ahora **sí aparece** en
  "Pedido entregado" el día de su entrega.
- **Productos más vendidos** (`getTopSellingProductsByQuantitySold`): pasó de
  `confirmed_at` a `closed_at` → ahora cuadra con "Productos vendidos".
- **Ya estaban correctos** (Opción B): ventas por usuario/ciudad (`won` + `closed_at`),
  leads por usuario/ciudad (`created_at`), productos solicitados (todas las etapas +
  `created_at`).

### 3.3 Card "Evolución" (rehecho para que se entienda)
- **Antes:** comparaba la línea actual contra una línea plana = promedio anterior (confuso).
- **Ahora:** dos líneas reales - **período actual** vs **período anterior** - comparables
  punto a punto.
- Cifra grande = **total** del período + promedio por día/semana + rango de fechas + chip
  ▲/▼ % vs anterior.
- **Tabs reordenados/renombrados:**
  `Pedidos entregados | Prospectos | Valor de ventas | Productos vendidos`
  (antes "Ventas" y "Pedidos creados").
- Backend (`buildEvolutionPayload`) ahora devuelve: serie anterior alineada, totales,
  promedios, período (día/semana/mes), rangos de fechas comparados, progreso.
- Definición de cada tab:
  - **Pedidos entregados** = nº de leads en etapa entregado, por `closed_at`.
  - **Prospectos** = leads creados, por `created_at`.
  - **Valor de ventas** = Σ `lead_value` de entregados, por `closed_at`.
  - **Productos vendidos** = Σ cantidades de entregados, por `closed_at`.

### 3.4 Otros cambios ya hechos
- **KPI nuevo "Total productos vendidos"** (entregados, por `closed_at`) junto a
  "Total productos solicitados".
- **Card combinado "Prospectos vs. Pedidos entregados"** (barras + línea).
- **Filtros globales** de ciudad y fecha: cookies sin cifrar compartidas entre dashboard
  y módulo de pedidos; el cookie es el **default en el servidor** (evita el "parpadeo"/
  carrera que dejaba cards en blanco al cambiar de módulo).
- **Léxico** unificado en leyendas; colores de leyenda con `style` inline (las clases
  Tailwind arbitrarias `bg-[#hex]` no estaban en el CSS compilado y salían sin color).

### 3.5 Verificación con datos reales
- **Día 23** (todas las ciudades): solo 2 leads - #162 Prospecto (creado 23) y #158
  Pedido entregado (entregado 23, 9 prod, Bs. 420).
  - Funnel: Prospectos **1** · Entregado **1**.
  - Productos más vendidos: Red Blend **6** · Malbec **3** = **9**.
  - KPIs: solicitados **0** · vendidos **9**.
  - Evolución: Entregados **1** · Prospectos **1** · Valor **420** · Prod. vendidos **9**.
  - **Todo cuadra.**

---

## 4. PENDIENTE

### 4.1 Barra "Prospectos" del card "Total de Prospectos" (overcount)
- **Problema:** `getTotalLeadsOverTime` cuenta TODOS los leads creados no perdidos, sin
  importar su etapa actual. En el rango **18–23 jun** da **5** "Prospectos" cuando solo
  **3** siguen realmente en esa etapa (cuela #158 que ya es Entregado y #161 que ya es
  Confirmado). Además #158 se cuenta **doble**: en "Prospectos" el 18 y en "Pedidos
  entregados" el 23.
- **Datos reales 18–23:** creados = #158(entregado), #159(prosp), #160(prosp),
  #161(confirmado), #162(prosp). Barra dice 5; en etapa Prospecto hay 3.
- **Fix propuesto:** limitar la serie "Prospectos" a los IDs de **etapa Prospecto**
  (sigue por `created_at`). Esperado 18–23: **5 → 3**, cuadra con kanban/funnel.

### 4.2 Frontend de lead_value (completar Opción 3 en pantalla)
El backend ya enforce la regla; falta la UI:
- Campo `lead_value` **read-only para asesores**, autollenado **en vivo** con Σ productos
  (el componente de productos ya emite `onProductListUpdated` con el total).
- **Checkbox "Editar valor manualmente"** visible **solo** para Supervisor/Administrador →
  habilita el campo y envía `lead_value_is_manual = 1`.
- Nota de riesgo: el campo se renderiza por el componente genérico de atributos, así que
  hay que hacerlo con cuidado y probarlo en navegador.

---

## 5. Testing - estado y deuda

- Los tests **no** usan `RefreshDatabase` ni transacciones → si se corren contra
  producción, **insertan datos basura** (usuarios/leads/productos de prueba).
- **Procedimiento seguro (el que se usó):**
  1. Crear BD `kohlberg_test` (con root del contenedor MySQL).
  2. `migrate` + `db:seed` con `-e DB_DATABASE=kohlberg_test`.
  3. `php artisan test` con el mismo override.
  4. `DROP DATABASE kohlberg_test`.
  → Producción nunca se toca.
- **Última corrida:** 9 pasaron, 14 fallaron. **Ningún fallo es por estos cambios:**
  - Mayoría: `Cannot truncate a table referenced in a foreign key constraint` (los tests
    truncan tablas con FK sin desactivar checks → problema de entorno preexistente).
  - `PersonSucursalReportingTest`: falla en `Bouncer.php:70` ("view_permission on null")
    = setup sin usuario autenticado, código no tocado.
  - Resto: módulos ajenos (doctor, specialty, product-attribute).
  - **Pasaron:** Auth, "ver dashboard tras login" (carga el reporting sin 500),
    `LeadViewControllerTest`, etc.
- **Deuda técnica (fuera de alcance actual):** dar aislamiento al suite (test DB dedicada
  o trait `DatabaseTransactions`) y arreglar los truncate/seed para que sea fiable.

---

## 6. Archivos tocados (referencia)

- `app/Http/Middleware/EncryptCookies.php` - cookies de filtro global sin cifrar.
- `packages/Webkul/Admin/src/Http/Controllers/DashboardController.php` - defaults de filtro
  desde cookie en servidor.
- `packages/Webkul/Admin/src/Http/Controllers/Lead/LeadController.php` - filtros globales +
  kanban fecha por etapa (Opción B).
- `packages/Webkul/Admin/src/Helpers/Dashboard.php` - KPIs, evolución.
- `packages/Webkul/Admin/src/Helpers/Reporting/Lead.php` - confirmedStageIds,
  stageEventDateExpr, funnel, evolución.
- `packages/Webkul/Admin/src/Helpers/Reporting/Product.php` - productos vendidos/solicitados,
  top vendidos (closed_at).
- `packages/Webkul/Lead/src/Repositories/LeadRepository.php` - amount + regla lead_value.
- `packages/Webkul/Lead/src/Models/Lead.php` - fillable/cast lead_value_is_manual.
- `packages/Webkul/Lead/src/Database/Migrations/2026_06_24_000001_add_lead_value_is_manual_to_leads_table.php`
- `packages/Webkul/Admin/src/Resources/views/dashboard/index/*` - evolution, over-all,
  total-leads, total-leads-vs-entregados, index.
- `packages/Webkul/Admin/src/Resources/views/leads/index/*` - date-filters, view-switcher,
  kanban, table.
- `packages/Webkul/Admin/src/Resources/lang/{es,en}/app.php` - léxico.

---

## 7. Orden sugerido para continuar

1. **Fix 4.1** (barra "Prospectos" → solo etapa Prospecto) + verificar 18–23 en BD.
2. **Frontend 4.2** (read-only asesores + checkbox override Supervisor/Admin) + probar en
   navegador.
3. (Opcional) Aislar el suite de tests.
