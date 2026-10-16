# Todo: Tablero consistente

Spec: `docs/specs/tablero-consistente.md` · Plan: `tasks/plan-tablero-consistente.md`
Pendientes fuera de alcance: **P2 "Sin ciudad"** y **P5 "Tiempo en responder"** (no se tocan).

Comandos (siempre como www-data):

```bash
C=$(docker ps --format '{{.Names}}' | grep imprimir_laravel)
docker exec -u www-data -w /code $C php artisan test tests/Feature/Dashboard
docker exec -u www-data -w /code $C ./vendor/bin/pint packages/Webkul/Admin/src/Helpers/Reporting
```

---

## Fase 1 · Base

- [x] **T1 · Reglas compartidas en `AbstractReporting`** (S)
  - Descripción: agregar `asesoresVisibles()` (envuelve `bouncer()->getAuthorizedUserIds()`), `ejecutivosIds()` (usuarios con rol "Ejecutivo Comercial"), `pedidosDelTablero($query, $leads = 'leads')` (ciudad `pipeline_id` + ACL) y `excluirSinCiudad($query, $leads = 'leads')`. Los métodos existentes que hoy excluyen "Sin ciudad" pasan a usar `excluirSinCiudad()`. Todavía no cambia ningún número.
  - Aceptación:
    - [ ] Ningún método de `Lead`/`Product` llama a `sinCiudadPipelineIds()` directamente.
    - [ ] Los tests actuales de `tests/Feature/Dashboard` pasan sin modificarse.
  - Verificar: `php artisan test tests/Feature/Dashboard`; `audit.php 2026-10-01 2026-10-14 1` da los mismos valores que la auditoría.
  - Archivos: `Helpers/Reporting/AbstractReporting.php`, `Helpers/Reporting/Lead.php`, `Helpers/Reporting/Product.php`
  - Depende de: —

### ✅ Checkpoint 1: tests en verde y números idénticos a la auditoría.

---

## Fase 2 · Productos

- [x] **T2 · Solicitados = unidades cotizadas (D3)** (M)
  - Descripción: nuevo `Product::solicitadosPorProducto()` sobre `quote_items` ⋈ `quotes` ⋈ `lead_quotes` ⋈ `leads`, por `leads.created_at`, con `pedidosDelTablero()` (y `excluirSinCiudad()` según la pregunta abierta 1). Devuelve `unidades` y `cotizaciones` por producto. `getTotalServices()` (KPI "Total productos solicitados") = suma de unidades; `getTopSellingProductsByQuantity()` (torta) = la lista ordenada por unidades.
  - Aceptación:
    - [ ] Suma de la torta = KPI, con y sin filtro de ciudad.
    - [ ] Ninguno de los dos lee `lead_products`.
    - [ ] El test "cuenta en cuántas cotizaciones aparece" pasa a "suma las unidades cotizadas" (se dice en el commit).
  - Verificar: test nuevo con 4 cotizaciones de Magia Verde que suman 29 → la porción dice 29 y el KPI 29. Producción 1–14/10: Magia Verde 1199, Stretch 505 o lo que dé con la regla de "Sin ciudad" elegida.
  - Archivos: `Helpers/Reporting/Product.php`, `tests/Feature/Dashboard/TarjetasAtencionProductosTest.php`, `tests/Feature/Dashboard/TableroConsistenteTest.php` (nuevo)
  - Depende de: T1

- [x] **T3 · Vendidos = unidades cotizadas de entregados (D4, D5)** (M)
  - Descripción: `Product::vendidosPorProducto()` es igual a T2, pero con `leads.lead_pipeline_stage_id IN wonStageIds`. `getTotalProductsSold()` (KPI) = suma de unidades; `getTopSellingProductsByQuantitySold()` (torta) = lista. Sale el `HAVING SUM(lead_products.quantity) > 0`.
  - Aceptación:
    - [ ] Suma de la torta "Más vendidos" = KPI "Total productos vendidos (Entregados)".
    - [ ] Un entregado con cotización de 10 rollos suma 10; uno sin cotización suma 0.
  - Verificar: tests; producción 1–14/10 → 0 y torta vacía (los 2 entregados no tienen cotización).
  - Archivos: `Helpers/Reporting/Product.php`, `tests/Feature/Dashboard/TableroConsistenteTest.php`
  - Depende de: T2

- [x] **T4 · Evolución: productos vendidos y valor de ventas desde cotizaciones** (S)
  - Descripción: `getUnitsSoldOverTime()` usa `vendidosPorProducto()` agrupado por día o semana. `getEvolution('valor-ventas')` suma `quotes.grand_total` de las cotizaciones de entregados, en vez de `leads.lead_value`.
  - Aceptación:
    - [ ] Total de la pestaña "Productos vendidos" = KPI de T3.
    - [ ] Total de "Valor de ventas" = suma de `grand_total` de las cotizaciones de entregados del rango.
  - Verificar: tests; la pestaña en el navegador con el rango 1–14/10.
  - Archivos: `Helpers/Reporting/Product.php`, `Helpers/Reporting/Lead.php`, `tests/Feature/Dashboard/TableroConsistenteTest.php`
  - Depende de: T3

- [x] **T5 · Aviso de entregados sin cotización (D6)** (M)
  - Descripción: `Lead::getEntregadosSinCotizacion()` devuelve el conteo y la lista (`id`, título, asesor) de los pedidos en ENTREGADOS del rango, sin fila en `lead_quotes`, con `pedidosDelTablero()`. Nuevo `type` `entregados-sin-cotizacion` en `DashboardController`. Se muestra en el KPI de vendidos, en Evolución y en la torta "Más vendidos": "N pedidos entregados sin cotización — complétalos", y cada uno enlaza a `route('admin.leads.view', id)`. Si N = 0, no se muestra.
  - Aceptación:
    - [ ] Producción 1–14/10: N = 2, con enlaces a #1124 y #1669.
    - [ ] Al vincular una cotización a un pedido, deja de aparecer.
    - [ ] Respeta la ciudad y el ACL.
  - Verificar: test del endpoint; revisión visual.
  - Archivos: `Helpers/Reporting/Lead.php`, `Helpers/Dashboard.php`, `Http/Controllers/DashboardController.php`, `views/dashboard/index/over-all.blade.php`, `views/dashboard/index/quantity-products-sold-pie.blade.php`
  - Depende de: T3

### ✅ Checkpoint 2 (revisar con el usuario) — 15/10
Resultado: 19 tests del tablero en verde. Producción 1–14/10: solicitados 1738 = torta (Javier), 1501 = torta (Mauricio, ACL), 1057 = torta (Santa Cruz); vendidos 0, aviso de 2 (#1669, #1124).
Extra: «Valor de pedidos ganados» del Excel también pasa a cotizaciones (getTotalWonLeadValue), si no quedaba distinto del tablero.
Fuera de alcance, ya roto antes: la suite completa no arranca por `ciudadDe()` duplicada (Contact/SincronizarCiudadTest y Quote/CiudadYSucursalTest); 2 tests de Product (CotizacionAgenteTest, EntregaTest) fallan igual sin estos cambios.
- [ ] Tests en verde.
- [ ] Producción 1–14/10: solicitados KPI = suma de la torta; vendidos 0 con aviso de 2.
- [ ] La exportación a Excel (`/admin/dashboard/export`) coincide con el tablero.

---

## Fase 3 · Personas y permisos

- [→] **T6 · (movida a A3 de `tasks/todo-tablero-por-asesor.md`, 16/10)** Tarjetas por asesor: solo Ejecutivo Comercial (D7)** (M)
  - Descripción: "Atendidos vs no atendidos", `leads-by-users` y "Ventas por vendedor" listan solo `ejecutivosIds()`. Se devuelve `otros` = pedidos del universo cuyo `user_id` es NULL o no es ejecutivo, y la tarjeta muestra "N pedidos de otros usuarios o sin asesor" cuando N > 0. Las tarjetas **por ciudad** y los totales dejan de usar `excludeIgnoredRoles()` (cuentan todos los pedidos). `getAverageResponseTimeByUsers()` **no se toca** (P5).
  - Aceptación:
    - [ ] Suma por asesor + `otros` = total de la tarjeta.
    - [ ] Un pedido del Supervisor o sin asesor aparece en `otros`, no desaparece.
    - [ ] Hoy, en producción: `otros` = 0.
  - Verificar: tests con un pedido del Administrador, uno del Agente y uno sin asesor.
  - Archivos: `Helpers/Reporting/Lead.php`, `views/dashboard/index/leads-by-users.blade.php`, `views/dashboard/index/ventas.blade.php`, `tests/Feature/Dashboard/TableroConsistenteTest.php`
  - Depende de: T1

- [→] **T7 · (reemplazada por A1–A2 de `tasks/todo-tablero-por-asesor.md`: alcance por rol con `Alcance`, 16/10)** ACL en todas las tarjetas (D8)** (M)
  - Descripción: `pedidosDelTablero()` (con ACL) se aplica en `getTotalLeads`, `getVentasCount`, `getTotalWonLeadValue`, `getOverTimeStats`, `getOpenLeadsByStatesFixed` y en los métodos de productos de T2 y T3. "Total de contactos" queda fuera (pregunta abierta 2).
  - Aceptación:
    - [ ] Con Mauricio (`group`): embudo = suma de "Atendidos vs no atendidos" = suma por ciudad, todo dentro de su grupo.
    - [ ] Con el Administrador (`global`): ningún número cambia respecto del checkpoint 2.
  - Verificar: test con un usuario `individual`; `audit.php … 257` en producción.
  - Archivos: `Helpers/Reporting/Lead.php`, `Helpers/Reporting/Product.php`, `tests/Feature/Dashboard/TableroConsistenteTest.php`
  - Depende de: T6 (y T2/T3 si ya están)

### ✅ Checkpoint 3 (revisar con el usuario)
- [ ] Tests en verde.
- [ ] `audit.php` con los usuarios 1 y 257: cada total = la suma de su desglose.

---

## Fase 4 · Pantalla

- [ ] **T8 · Títulos y grilla (D9)** (S)
  - Descripción: el embudo pasa a "Pedidos por etapa". `$isAdminRole` → `in_array($user->role?->name, ['Administrador', 'Supervisores'])`. Se saca el `leads-by-users` duplicado de la grilla. "Tiempo en responder" sigue igual para los demás roles.
  - Aceptación:
    - [ ] Javier, Danitza, Andrés y Mariano ven Ventas por vendedor, Leads por ciudad y Ventas por ciudad.
    - [ ] Un ejecutivo no ve la grilla.
    - [ ] "Atendidos vs no atendidos" aparece una sola vez.
  - Verificar: test de vista (respuesta de `/admin/dashboard` por rol) y revisión visual.
  - Archivos: `views/dashboard/index.blade.php`, `views/dashboard/index/open-leads-by-states-fixed.blade.php`
  - Depende de: —

- [ ] **T9 · Tortas con número y total (D2b)** (S)
  - Descripción: en "No atendidos por ciudad", "Más solicitados" y "Más vendidos", la leyenda dice "Nombre — N" (en productos: "1199 u. · 42 cotizaciones") y la cabecera muestra el total. Solo frontend.
  - Aceptación:
    - [ ] Se lee el 57 (o el valor del rango) sin pasar el mouse.
    - [ ] El total de cada torta = la suma de su leyenda.
  - Verificar: revisión visual en claro y oscuro, y en ancho de celular.
  - Archivos: `views/dashboard/index/leads-por-ciudad-pie.blade.php`, `views/dashboard/index/quantity-products-pie.blade.php`, `views/dashboard/index/quantity-products-sold-pie.blade.php`
  - Depende de: T2, T3

- [ ] **T10 · Invariantes, script y verificación final** (M)
  - Descripción: completar `TableroConsistenteTest` con las invariantes del spec que no dependen de "Sin ciudad" (1, 2, 5, 6, 7, 8 y productos). Versionar el script de auditoría en `scripts/auditoria-tablero.php`. Correrlo contra producción y marcar los criterios de éxito en el spec.
  - Aceptación:
    - [ ] Invariantes en verde.
    - [ ] Criterios de éxito del spec marcados, salvo los de P2 y P5.
  - Verificar: `php artisan test tests/Feature/Dashboard`; `php scripts/auditoria-tablero.php 2026-10-01 2026-10-14 1` y `… 257`.
  - Archivos: `tests/Feature/Dashboard/TableroConsistenteTest.php`, `scripts/auditoria-tablero.php`, `docs/specs/tablero-consistente.md`
  - Depende de: T1–T9

### ✅ Checkpoint final
- [ ] Pint sin cambios pendientes, tests en verde.
- [ ] Deploy y `php artisan view:clear`.
- [ ] El usuario revisa el tablero con el rango 1–14/10.
