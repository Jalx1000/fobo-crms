# Spec: Tablero e inbox por asesor

Estado: **implementado el 16/10/2026** (fases A, B y C; commits en la rama `imprimir-modulo-whatsapp`). Ver `tasks/todo-tablero-por-asesor.md`. Adaptado de ponce_de_leon
(`docs/specs/tablero-por-asesor.md`, implementado el 14/10/2026).

## Objetivo

Cada asesor ve **solo lo suyo**: en el tablero, los números de sus pedidos; en el inbox de
WhatsApp, sus conversaciones. El total de la empresa lo ven los administradores y supervisores.

**Por qué:** hoy cualquier asesor ve en el tablero los totales de la empresa y, en el inbox, las
393 conversaciones de todos. Un asesor no debe conocer la cartera ni los resultados de los demás.

### Historias

- Como **asesor**, abro el tablero y cada tarjeta muestra mis números; los totales son la suma de mis pedidos.
- Como **administrador o supervisor**, veo el total de la empresa y el desglose por asesor, como hoy.
- Como **asesor**, en el inbox veo y atiendo solo mis conversaciones. No puedo abrir ni escribir en la de
  otro asesor, aunque arme la URL a mano.
- Como **asesor**, si pido por URL una tarjeta o el Excel, no recibo datos ajenos.

## Cómo está hoy (relevado el 15/10)

**Usuarios** (cambiados hoy a las 18:17–18:18: los ejecutivos pasaron a `individual` y sin grupos):

| Id | Usuario | Rol | Permiso de vista |
|---|---|---|---|
| 1, 2, 2869 | Javier Mogro, Danitza Cuellar, Meta developer | Administrador | global |
| 12, 256 | Andrés González Requena, Mariano Pinell | Supervisores | global |
| 13 | Agente | Sistema | global |
| 254, 255, 257, 1052, 1053 | Bulmaro, Eduardo, Mauricio, Fabio, Gabriela | Ejecutivo Comercial | **individual** |

**Tablero:**
- Las tarjetas de productos y ventas ya respetan el ACL (`pedidosDelTablero()`, spec tablero-consistente, fase 2).
- Siguen mostrando el total de la empresa a cualquiera: KPIs de contactos, embudo, Evolución (pedidos entregados),
  prospectos, ventas totales y el Excel resumen.
- Las tarjetas por asesor y por ciudad ya aplican el ACL.

**Inbox de WhatsApp** (`packages/Webkul/Whatsapp`):
- `ChatController::conversations` lista **todas** las conversaciones a cualquier usuario. "Míos" es un filtro opcional.
- Ningún endpoint de `InboxController` (`thread`, `send`, `send-media`, `send-location`, `send-template`,
  `agent`, `lead`, `notes`, `handoff`, `handoff/claim`, `handoff/resolve`, `handoff/reassign`) ni de
  `ChatController` (`link-person`, `persons/{id}`, `persons/{id}/leads`, `leads/{id}/stage`) verifica a quién le pertenece la conversación, el contacto o el pedido.
- **Ya existe una regla de "dueño"** (`$deAsesor` en `ChatController`): la derivación abierta → el dueño del
  pedido → el dueño del contacto. La usan el filtro "Míos" y el selector de asesor.
- Datos: 393 conversaciones (391 Kommo, 2 Messenger), **todas con pedido y contacto**. Dueños: Bulmaro 160,
  Eduardo 143, Mauricio 52, Gabriela 34, Fabio 4. Derivaciones: 6 asignadas, 75 resueltas, 0 sin asignar.
  Ninguna derivación abierta está asignada a alguien distinto del dueño del pedido, y ningún contacto tiene pedidos de dos asesores.

## Decisiones propuestas

| # | Decisión | Propuesta | Origen |
|---|---|---|---|
| D1 | Quién ve el total | Roles **Administrador** y **Supervisores**, en config (`reporting.roles_con_total`). Hoy coincide con quienes tienen permiso `global`. | ponce D1 |
| D2 | Qué ve un asesor | Las mismas tarjetas, calculadas solo sobre **sus** pedidos (`leads.user_id` = él). | ponce D2 |
| D3 | Dónde se aplica | Una sola clase `Webkul\Admin\Helpers\Alcance` (copiada de ponce). `pedidosDelTablero()` pasa a usarla en vez de `bouncer()`. El endpoint `stats` y el Excel la aplican en el servidor. | ponce D3 |
| D4 | Ranking por asesor | El asesor ve **solo su fila**; administradores y supervisores, todas. | ponce D5 |
| D5 | `user_id` en la URL | Un asesor solo puede pedir su propio id; cualquier otro se ignora. | ponce D6 |
| D6 | Títulos | Para el asesor, "Mis …" (p. ej. "Mis productos solicitados"); para administradores y supervisores, los títulos actuales. | ponce D7 |
| D7 | Excel | La hoja resumen y la de detalle respetan el mismo alcance. | ponce D8 |
| D8 | Conversaciones de un asesor | **La misma regla que "Míos"**: derivación abierta asignada a él, o, si no hay derivación abierta, pedido de él, o, sin pedido, contacto de él. Así "Míos" y "lo que puedo ver" son lo mismo. | **distinto de ponce** (allá: solo el pedido) |
| D9 | Conversaciones sin contacto ni pedido | Solo los **administradores** (hoy no hay ninguna). | ponce D11/D17 |
| D10 | Una sola regla en WhatsApp | `Whatsapp\Services\ConversacionesVisibles` (copiada de ponce y adaptada a D8), usada por el listado y por **cada** endpoint que recibe una conversación, un contacto, un pedido o un teléfono. Si no corresponde: **404**. | ponce D12 |
| D11 | Administrador | Sin ninguna restricción en ningún módulo. | ponce D17 |
| D12 | Derivaciones sin asignar (pool) | **Nunca quedan en el pool: se asignan por turno (round robin) según la tabla de reparto.** Ya es lo que hace `Handoff::ownerFor()`: dueño del pedido o del contacto → turno de la ciudad en la matriz → turno nacional («Bolivia») sin los de `ASESORES_FUERA_DEL_TURNO`. Se agrega: (a) los ejecutivos no ven el filtro «pool»; (b) si una derivación queda sin asignar (matriz sin nadie para ese caso), se reintenta por turno al pedirla de nuevo y la ven administradores y supervisores, que la asignan con «Reasignar». Fuera de horario se asigna igual (regla actual del reparto). | **respondido 16/10** |
| D13 | Cambiar el asesor de un pedido | **Solo Administrador y Supervisores.** Para un ejecutivo, el campo Asesor es de solo lectura (ficha y formulario) y el servidor rechaza el cambio de `user_id` en edición, edición en línea, actualización masiva y en el inbox («Reasignar» derivación). El reparto automático (`Responsables`) sigue pudiendo moverlos: no pasa por un usuario. | ponce D15 · **respondido 16/10** |
| D13b | Contactos | **Los ejecutivos tienen permiso `individual` para no pelearse por los contactos** (lo cambió el usuario el 15/10). El listado de contactos ya filtra por dueño, pero la ficha, la edición y el guardado de un contacto (`PersonController::view/edit/update`) no lo verifican: un ejecutivo puede abrir por URL el contacto de otro y cambiarle el «Asesor Asignado» (`persons.user_id`). Regla: para quien no ve el total, un contacto ajeno responde **404**, y el «Asesor Asignado» de un contacto solo lo cambian Administrador y Supervisores (el reparto automático sigue pudiendo). | relevado 16/10 |
| D13c | Cotizaciones | Igual que contactos: para quien no ve el total, una cotización que no es suya ni de un pedido suyo da **404** en editar, guardar, imprimir, borrar y enviar (correo, WhatsApp, Messenger); crear una cotización para el pedido de otro, también. El «Asesor de venta» lo cambian solo Administrador y Supervisores (las cotizaciones siguen al asesor del pedido por `Responsables`). | relevado 16/10 |

**Lo que no cambia:** la API del agente (`api/v1/whatsapp/...`, con token) y los webhooks; el reparto producto × ciudad;
el kanban y la lista de pedidos (ya filtran por `individual`).

### Relación con el spec tablero-consistente

- **T7 (ACL en todas las tarjetas)** queda reemplazada por este spec: se implementa con `Alcance` y no con `bouncer()`.
- **D8 de tablero-consistente** ("asesor con permiso `group` ve su grupo") queda sin efecto: ya no hay grupos.
- **T6** (por asesor, solo ejecutivos) sigue en pie y se hace antes, porque define las filas que se recortan en D4.

### Supuestos

1. "Pedidos de un asesor" = `leads.user_id` = él (el dueño).
2. Sin usuario logueado (comandos, jobs, API del agente) no se recorta nada.
3. Ocultar en la vista no alcanza: el filtro va en el servidor.
4. No hay cambios de esquema.

## Comandos

```bash
C=$(docker ps --format '{{.Names}}' | grep imprimir_laravel)
docker exec -u www-data -w /code $C php artisan test tests/Feature/Dashboard tests/Feature/Whatsapp
docker exec -u www-data -w /code -e TMPDIR=/code/storage/framework/cache $C ./vendor/bin/pint packages/Webkul/Admin/src/Helpers packages/Webkul/Whatsapp/src
docker exec -u www-data -w /code $C php artisan view:clear
# Después del deploy, reiniciar el worker de la cola whatsapp (supervisord dentro del contenedor)
```

## Estructura

```
packages/Webkul/Admin/src/Helpers/Alcance.php                         → regla única (nuevo, de ponce)
packages/Webkul/Admin/src/Helpers/Reporting/AbstractReporting.php     → pedidosDelTablero() usa Alcance
packages/Webkul/Admin/src/Http/Controllers/DashboardController.php    → user_id forzado, alcance en stats y export
packages/Webkul/Admin/src/Resources/views/dashboard/                  → títulos "Mis …"
packages/Webkul/Admin/src/Config/reporting.php (o equivalente)        → roles_con_total
packages/Webkul/Whatsapp/src/Services/ConversacionesVisibles.php      → regla del inbox (nuevo, de ponce, con D8)
packages/Webkul/Whatsapp/src/Http/Controllers/{Chat,Inbox}Controller.php → listado y endpoints
tests/Feature/Dashboard/TableroPorAsesorTest.php                      → nuevo
tests/Feature/Whatsapp/InboxPorAsesorTest.php                         → nuevo
```

## Estrategia de tests

Pest, `DatabaseTransactions`, fechas de enero de 2020. **Ojo:** como en ponce, los tests corren contra
la base real dentro de una transacción; no se agregan tests que hagan commit.

- Escenario: 2 ejecutivos, 1 supervisor y 1 administrador, con pedidos y conversaciones de cada ejecutivo.
- Un test **recorre todos los `type`** de `DashboardController::$typeFunctions` y falla si un ejecutivo recibe datos de otro.
- Un test **recorre todas las rutas** del inbox con el id de una conversación ajena y espera 404.
- Regresión: el administrador y el supervisor ven exactamente lo mismo que antes.

## Límites

- **Siempre:** filtrar en el servidor; una sola regla por módulo; tests antes del commit.
- **Preguntar antes:** cambiar roles o permisos de usuarios reales; quitar tarjetas.
- **Nunca:** ocultar solo en el front; cambiar lo que ve un administrador; tocar la API del agente.

## Criterios de éxito

1. Logueado como Bulmaro, cada tarjeta de `stats?type=…` y el Excel dan solo números de sus pedidos.
2. `stats?type=over-all&user_id=<Eduardo>` logueado como Bulmaro devuelve los de Bulmaro.
3. Como administrador o supervisor, todas las tarjetas dan lo mismo que antes.
4. Como Bulmaro, el inbox muestra solo sus conversaciones (hoy: 160), y cada endpoint del inbox responde 404 con una conversación de Eduardo.
5. "Míos" y la lista completa de un ejecutivo son el mismo conjunto.
6. Como administrador o supervisor, el inbox muestra las 393 conversaciones, como hoy.

## Preguntas

Respondidas el 16/10/2026:

1. ~~P1~~ Ven el total: **Administrador y Supervisores** (D1).
2. ~~P2~~ Conversaciones de un ejecutivo: **la regla de «Míos»** (D8).
3. ~~P3~~ Pool: **round robin según la tabla de reparto** (D12).
4. ~~P4~~ Cambiar el asesor: **solo Administrador y Supervisores** (D13).

5. ~~P5~~ **Matriz de reparto: se deja como está** (respondido 16/10). El round robin usa la matriz cargada; la tabla del 16/10 queda como referencia:

   **P5 · La tabla de reparto que se dio el 16/10 no coincide con la matriz cargada** (`producto_responsables`):

| Asesor | Tabla del 16/10 | Matriz cargada |
|---|---|---|
| Bulmaro Vaca | Stretch Film · Bolivia | Stretch **+ Hules + Tapas** · Bolivia (Hules y Tapas agregados el 14/10) |
| Eduardo Lujan | Magia Verde · Bolivia | igual |
| Fabio Sandoval | Hules · Santa Cruz | igual |
| Mauricio Garces | los 4 · Cochabamba | igual |
| Gabriela Marconi | los 4 · La Paz | igual |
| Mariano Pinell | los 4 · Bolivia | **ninguna casilla**, y está en `ASESORES_FUERA_DEL_TURNO` desde el 13/10 |

Si se aplica la tabla tal cual, Mariano entra al turno nacional de los 4 productos: en las ciudades sin asesor
local para ese producto (p. ej. Stretch o Magia Verde en Santa Cruz, Tarija, Sucre…) se reparte entre él y
Bulmaro (Stretch) o Eduardo (Magia Verde), y le tocan también los que llegan sin ciudad. No se aplica: no se tocan datos de producción.

## Encargado nacional (20/10/2026, pedido del usuario)

| # | Decisión |
|---|---|
| E1 | Bulmaro, encargado de Bolivia, sigue como **Ejecutivo Comercial** con vista individual: ve solo lo suyo, sigue en el reparto y en las tarjetas por asesor. |
| E2 | Puede **reasignar** sus pedidos, cotizaciones, contactos y derivaciones, **solo a otro Ejecutivo Comercial** activo. Al pasar un pedido, su cotización, contacto y conversación lo siguen (`Responsables::todoConElPedido`) y deja de verlos. |
| E3 | Se marca en `reporting.encargados_nacionales` (env `ENCARGADOS_NACIONALES`, correos separados por coma; por defecto el de Bulmaro). La regla vive en `Alcance::asesorPermitido()`. |
| E4 | Su lista de asesores (Pedidos, Cotizaciones, Contactos) trae solo ejecutivos comerciales. Administrador y Supervisores eligen entre todos, aunque su vista sea individual. |
