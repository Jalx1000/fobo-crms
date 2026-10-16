# Todo: Tablero e inbox por asesor

Spec: `docs/specs/tablero-por-asesor.md` · Plan: `tasks/plan-tablero-por-asesor.md`

```bash
C=$(docker ps --format '{{.Names}}' | grep imprimir_laravel)
docker exec -u www-data -w /code $C php artisan test tests/Feature/Dashboard
docker exec -u www-data -w /code $C php artisan test tests/Feature/Whatsapp
docker exec -u www-data -w /code $C php artisan test tests/Feature/Lead
docker exec -u www-data -w /code -e TMPDIR=/code/storage/framework/cache $C ./vendor/bin/pint <archivos>
```

---

## Arreglo fuera del plan (16/10)

- [x] La etapa se renombró en el CRM «NO ATENIDOS» → «NO ATENDIDOS» (código `no-atendidos`). `CotizacionEnviada` y `AgenteController::aSolicitudRecibida` solo avanzaban desde `no-atenidos`: el pedido dejaba de moverse solo. `Stage::NO_ATENDIDOS` acepta los dos códigos; tests actualizados al código nuevo. Sin pedidos afectados todavía (0 en NO ATENDIDOS con cotización en los últimos 2 días).

## Fase A · Tablero

- [x] **A1 · Alcance y scope del endpoint** (M) — 16/10: 5 tests nuevos; por HTTP, Bulmaro pidiendo user_id=255 recibe lo suyo (481 solicitados, 103 prospectos, embudo 147). El scope global de Lead pregunta a Alcance en cada consulta (estático: no puede guardar el id). Falta «Total de contactos» (A2).
  - Descripción: copiar `Alcance` de ponce a `packages/Webkul/Admin/src/Helpers/Alcance.php` (sin `veSinAsignar` por config: D9 = solo administradores). Config `reporting.roles_con_total` = `['Administrador', 'Supervisores']` y `reporting.roles_sin_restriccion` = `['Administrador']`. `AbstractReporting::asesoresVisibles()` devuelve `null` si ve el total, o `[userId]`. `DashboardController::aplicarAlcance()` en `stats()` y `export()`: si no ve el total, `request()->merge(['user_id' => su id])`.
  - Aceptación:
    - [ ] Como Bulmaro, `stats?type=quantity-products` solo trae unidades de sus pedidos.
    - [ ] Como Bulmaro, `stats?type=over-all&user_id=255` devuelve lo de Bulmaro.
    - [ ] Como Javier y como Mariano, nada cambia (los 19 tests actuales en verde).
  - Archivos: `Admin/src/Helpers/Alcance.php` (nuevo), `Admin/src/Config/reporting.php` (nuevo, registrado en el provider), `Reporting/AbstractReporting.php`, `DashboardController.php`, `tests/Feature/Dashboard/TableroPorAsesorTest.php` (nuevo)
  - Depende de: —

- [x] **A2 · Las tarjetas que faltan** (M) — 16/10: test que recorre los 27 `type` (antes/después de cargar datos de Eduardo). Se cerraron: cotizaciones (`quotes.user_id`), contactos (`persons.user_id`), organizaciones, `total-services`. De paso, 3 tarjetas ocultas que daban 500 (doble join de `leads` con `user_id` en top-selling-products y top-persons; `leads.created_at` sin prefijo `kl_` en total-leads-by-stages-over-time y total-services; esta última fallaba también para el administrador). En producción la suma de los 5 ejecutivos = total del administrador en prospectos 241, solicitados 1738, embudo 369, cotizaciones 83.
  - Descripción: `pedidosDelTablero()` (o `Alcance::leads()`) en `getTotalLeads`, `getAverageLeadsPerDay`, `getVentasCount`, `getTotalLostLeadValue`, `getOverTimeStats` (Evolución "Pedidos entregados"), `getOpenLeadsByStatesFixed` (embudo), `getTotalLeadsByStages*`. "Total de contactos": personas cuyo dueño es él (`persons.user_id`). Cotizaciones y organizaciones: las de sus pedidos. Test que recorre **todos** los `type` de `$typeFunctions` con dos ejecutivos y verifica que ninguno reciba datos del otro.
  - Aceptación:
    - [ ] El test de todos los `type` pasa.
    - [ ] Como Javier, mismos números que la auditoría del 15/10.
  - Archivos: `Reporting/Lead.php`, `Reporting/Person.php`, `Reporting/Quote.php`, `Reporting/Organization.php`, `TableroPorAsesorTest.php`
  - Depende de: A1

- [x] **A3 · Ranking por asesor (ex T6 de tablero-consistente)** (M) — 16/10: filas = `ejecutivosIds()` en los 5 rankings; `otros` en atendidos, leads-by-users y ventas, con nota en pantalla; las 5 tarjetas por ciudad/sucursal sin `excludeIgnoredRoles`. Producción: Bulmaro 1 fila; Javier 5 filas, otros 0.
  - Descripción: "Atendidos vs no atendidos", `leads-by-users`, "Ventas por vendedor" y "Tiempo en responder" listan solo usuarios con rol Ejecutivo Comercial (`ejecutivosIds()`). El ejecutivo ve solo su fila. Se devuelve `otros` (pedidos de no ejecutivos o sin asesor) y la tarjeta muestra la nota cuando es > 0. Las tarjetas por ciudad dejan de usar `excludeIgnoredRoles()`. ("Tiempo en responder" solo cambia **qué filas** muestra; su cálculo no se toca, P5.)
  - Aceptación:
    - [ ] Como Bulmaro, cada ranking tiene una sola barra: la suya.
    - [ ] Como Javier, las barras son los 5 ejecutivos, y la suma de barras + `otros` = total.
  - Archivos: `Reporting/Lead.php`, `views/dashboard/index/leads-by-users.blade.php`, `views/dashboard/index/ventas.blade.php`, `TableroPorAsesorTest.php`
  - Depende de: A1

- [x] **A4 · Excel y títulos «Mis …»** (S) — 16/10: `DashboardOrdersSheet` (la única hoja activa) usa Alcance en vez de bouncer; un supervisor con permiso individual igual exporta todo. 10 títulos con «Mis …» vía `$personal` (index.blade).
  - Descripción: el Excel (resumen y detalle) usa el mismo alcance (lo hereda de A1; se verifica la hoja de detalle `DashboardLeadsSheet`/`DashboardOrdersSheet`). Para quien no ve el total, los títulos de las tarjetas de totales dicen "Mis …" (`$personal` en la vista, como en ponce).
  - Aceptación:
    - [ ] El Excel de Bulmaro no tiene filas de otro asesor.
    - [ ] Los títulos "Mis …" aparecen solo para ejecutivos.
  - Archivos: `Exports/Dashboard/*.php` (si hace falta), `views/dashboard/index.blade.php`, `views/dashboard/index/over-all.blade.php` y las tarjetas con título de total
  - Depende de: A1, A2

### ✅ Checkpoint A (revisar con el usuario)

---

## Fase B · Inbox de WhatsApp

- [x] **B1 · ConversacionesVisibles y listado** (M) — 16/10: la regla de «Míos» salió de ChatController al servicio; lista = «Míos» para un ejecutivo; selector de asesores solo él. Producción: 162+145+52+34+4 = 397 = lo que ven Javier y Mariano.
  - Descripción: `Whatsapp/src/Services/ConversacionesVisibles.php` con `deAsesor($query, $userId)` (la regla de "Míos", movida desde `ChatController`), `aplicar($query)` (administrador: todo; quien ve el total: todo; ejecutivo: `deAsesor` con su id; sin contacto ni pedido: solo administradores), `puedeVer()`, `exigir()` (404) y `puedeVerContacto()`. `ChatController::conversations` llama a `aplicar()`, y "Míos" llama a `deAsesor()`.
  - Aceptación:
    - [ ] Como Bulmaro, el listado = el filtro "Míos" de Bulmaro.
    - [ ] Como Javier o Mariano, el listado no cambia.
  - Archivos: `Whatsapp/src/Services/ConversacionesVisibles.php` (nuevo), `Whatsapp/src/Http/Controllers/ChatController.php`, `tests/Feature/Whatsapp/InboxPorAsesorTest.php` (nuevo)
  - Depende de: A1

- [x] **B2 · Cada endpoint del inbox** (M) — 16/10: `existingConversation()` (thread y los 4 envíos) + las 7 rutas con {id} + 5 de ChatController. Test que recorre las rutas admin.whatsapp.*: todas 404 con la de otro. Un ejecutivo no abre conversación nueva a un número suelto (D9).
  - Descripción: `exigir()` en `thread`, `send`, `send-media`, `send-location`, `send-template` (por teléfono o id), `agent`, `lead`, `notes`, `handoff`, `handoff/claim`, `handoff/resolve`, `handoff/reassign`; en `ChatController`: `link-person`, `persons/{id}`, `persons/{id}/leads` (GET/POST) y `leads/{id}/stage`. La API del agente y los webhooks no se tocan.
  - Aceptación:
    - [ ] Un test recorre las rutas `admin.whatsapp.*` con parámetros y, como Bulmaro con una conversación de Eduardo, espera 404 en todas; con la suya, no 404.
  - Archivos: `InboxController.php`, `ChatController.php`, `InboxPorAsesorTest.php`
  - Depende de: B1

- [x] **B3 · Pool y Reasignar** (S) — 16/10: tomar y reasignar 403 para quien no ve el total; `Handoff::request` reintenta el turno si quedó sin asignar; filtros «Sin asesor»/«Sin contacto» y botón «Tomar» ocultos según el rol. Pendiente aparte: `admin.quotes.whatsapp` (módulo Cotizaciones) no verifica el asesor de la cotización.
  - Descripción: los filtros "pool" y "handoff" (todas las derivaciones) y la acción "Reasignar" solo para quien ve el total (403 en `reassign`, el filtro se ignora). Si `ownerFor()` devuelve null, la derivación queda visible para administradores y supervisores, y el siguiente `requestHandoff` vuelve a intentar el turno.
  - Aceptación:
    - [ ] Como Bulmaro, `?filter=pool` no trae nada ajeno y `handoff/reassign` responde 403.
    - [ ] Como Mariano, el pool y "Reasignar" funcionan como hoy.
  - Archivos: `ChatController.php`, `InboxController.php`, la vista del inbox (ocultar el filtro), `InboxPorAsesorTest.php`
  - Depende de: B2

### ✅ Checkpoint B (revisar con el usuario) + deploy (`view:clear`, reiniciar el worker de whatsapp)

---

## Fase C · Pedidos

- [x] **C1 · Solo Administrador y Supervisores cambian el asesor** (M) — 16/10: `LeadRepository::sinCambioDeAsesor()` en create/update (como ponce); «Asesor» de solo lectura en la ficha (`readOnly` nuevo en `x-admin::attributes.view`) y fuera de los formularios. Mass update solo cambia etapa. Reparto y agente escriben sin usuario o con DB::table: no afectados.
  - Descripción: `LeadRepository` rechaza el cambio de `user_id` (create con otro dueño y update) si el usuario logueado no ve el total; sin usuario logueado (reparto, agente, jobs) pasa. En la ficha y el formulario, el campo Asesor es de solo lectura para los ejecutivos. Cubre la edición en línea y la actualización masiva (`LeadController::massUpdate`).
  - Aceptación:
    - [ ] Como Bulmaro, un PUT, una edición en línea o una actualización masiva con otro `user_id` no lo cambia.
    - [ ] Como Mariano, sí lo cambia.
    - [ ] `Responsables::asignar()` sigue moviendo pedidos (test).
  - Archivos: `Lead/src/Repositories/LeadRepository.php`, `Admin/src/Http/Controllers/Lead/LeadController.php`, la vista del formulario y la ficha del pedido, `tests/Feature/Lead/AsesorSoloLecturaTest.php` (nuevo)
  - Depende de: A1

- [x] **C2 · Contactos: ficha ajena 404 y «Asesor Asignado» solo para Administrador y Supervisores** (M) — 16/10: middleware `ContactoDelAsesor` en el grupo de rutas de personas (las 8 rutas con {id}); `PersonRepository` ignora `user_id` de un ejecutivo y crea a su nombre. De paso: la edición en línea de cualquier campo dejaba el contacto sin asesor (normalizaba `user_id` aunque no viniera). Producción: Bulmaro 200 en su contacto y 404 en uno de Eduardo.
  - Descripción: los ejecutivos están en `individual` para no pelearse por los contactos (decisión del usuario, 15/10). `PersonController::view`, `edit`, `update` (y `destroy`) responden 404 si el contacto no es del ejecutivo (`persons.user_id`). `PersonRepository` rechaza el cambio de `user_id` si el usuario logueado no ve el total; sin usuario logueado (reparto, agente) pasa. En el formulario, "Asesor Asignado" es de solo lectura para los ejecutivos.
  - Aceptación:
    - [ ] Como Bulmaro, `/admin/contacts/persons/view/<contacto de Eduardo>` responde 404, y el PUT también.
    - [ ] Como Bulmaro, editar su propio contacto con otro `user_id` no cambia el dueño.
    - [ ] Como Mariano, todo funciona como hoy.
  - Archivos: `Admin/src/Http/Controllers/Contact/Persons/PersonController.php`, `Contact/src/Repositories/PersonRepository.php`, el formulario del contacto, `tests/Feature/Contact/ContactoPorAsesorTest.php` (nuevo)
  - Depende de: A1

## Pendientes encontrados (16/10)

- [x] Cotizaciones (D13c): middleware `CotizacionDelAsesor` en todas las rutas `quotes/{id}` y `create/{lead_id}`; «Asesor de venta» bloqueado en `QuoteRepository`. Antes un ejecutivo podía imprimir el PDF, editar y enviar por WhatsApp/Messenger/correo la cotización de otro.
- [ ] La suite completa (`php artisan test`) no arranca: `ciudadDe()` declarada en `Contact/SincronizarCiudadTest` y `Quote/CiudadYSucursalTest`.
- [ ] 2 tests de Product (`CotizacionAgenteTest`, `EntregaTest`) fallan desde antes de este trabajo.

### ✅ Checkpoint final
- [ ] Tests de Dashboard, Whatsapp y Lead en verde, cada uno por separado.
- [ ] Auditoría de solo lectura en producción como los 4 tipos de usuario.
- [ ] Deploy, `view:clear`, reinicio del worker.
