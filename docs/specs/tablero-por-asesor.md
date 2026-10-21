# Spec — Tablero de métricas independiente por asesor

- **Fase:** 4 de 4 (Specify ✅ → Plan ✅ → Tasks ✅ → **Implement** ✅)
- **Estado:** 🟢 Implementado el 14/10/2026 (ver §13). Pendiente: tests automatizados con base de prueba.
- **Pedido original:** «Independizar el tablero de métricas y sumatoria por asesor (no pueden ver
  el total de todos los prospectos, solo Sofopolis y los administradores).»

---

## 1. Objetivo

Que cada asesor, al abrir el tablero, vea **solo los números de sus propios leads**: sus
prospectos, sus pedidos, sus montos, sus tiempos. El total de la empresa (todos los
asesores sumados) lo ven **solo los administradores (incluida Sofopolis) y los
supervisores (Fernando)**.

**Por qué:** hoy el tablero le muestra a cualquier usuario el total de todos los
prospectos y las ventas de la empresa. Un asesor no debe conocer la cartera ni los
resultados de los demás.

### Historias

- Como **asesor**, abro el tablero y cada tarjeta muestra mis números; los totales son la
  suma de mis leads y de nadie más.
- Como **administrador / Sofopolis / supervisor**, abro el tablero y veo el total de la
  empresa y el desglose por asesor, como hoy.
- Como **asesor**, si pido por URL una tarjeta o un export con otro `user_id`, no obtengo
  datos ajenos.
- Como **asesor**, en la tarjeta «Qué necesita atención ahora» veo solo lo que me toca
  atender a mí.
- Como **asesor**, en el inbox de WhatsApp veo y atiendo solo mis conversaciones; no puedo
  abrir ni escribir en la de un cliente de otro asesor, ni siquiera armando la URL a mano.

---

## 2. Cómo está hoy (relevado en el código)

- El tablero pide cada tarjeta a `GET /admin/dashboard/stats?type=…`
  (`DashboardController::stats`), que llama a `Helpers/Dashboard.php` y de ahí a
  `Helpers/Reporting/*`.
- Krayin ya tiene el concepto de alcance por usuario: `bouncer()->getAuthorizedUserIds()`
  (`Admin/src/Bouncer.php`) devuelve:
  - `null` → permiso de vista **global** (ve todo),
  - ids de su grupo → permiso **group**,
  - `[su id]` → permiso **individual**.
  Es el mismo criterio que ya filtra el kanban y la tabla de leads.
- Usuarios activos hoy: **4 Administrador (global)**, **2 Asesor (individual)**,
  **0 Supervisores**.
- De las ~30 tarjetas, **solo 10 aplican ese filtro** (las «por vendedor», «por sucursal» y
  «por ciudad»). **Las otras ~19 muestran el total de la empresa a cualquiera**, entre ellas:
  `over-all`, `total-leads`, `revenue-stats`, `total-leads-by-stages(-over-time)`,
  `evolution`, `open-leads-by-states(-fixed)`, `salud-cartera`, `metas`,
  `inmuebles-solicitados`, `inmuebles-cerrados`, `operacion-whatsapp`, `top-persons`,
  `revenue-by-sources`, `revenue-by-types`, `total-services` y las de productos.
- El **export** del tablero (`/admin/dashboard/export`) arma la hoja resumen con el mismo
  helper, así que hoy también filtra los totales.

### 2b. Ampliación: «Qué necesita atención ahora» y módulo WhatsApp

- **La tarjeta** (`dashboard/index/operacion-hoy.blade.php`) pide `stats?type=operacion-whatsapp`
  y `stats?type=salud-cartera`. Sus cuatro números (`Whatsapp/src/Helpers/Reporting/Operacion.php`)
  cuentan **toda la empresa**: conversaciones sin responder, ventanas de 24 h por cerrarse,
  derivaciones sin tomar y conversaciones con la IA apagada.
- **El inbox de WhatsApp no tiene ningún filtro por usuario.** `ChatController::conversations`
  lista todas las conversaciones, y los endpoints de `InboxController` (`thread`, `send`,
  `send-media`, `send-location`, `media`, `notes`, `agent`, `handoff/claim`,
  `handoff/resolve`, `handoffs`) y de `ChatController` (`link-person`, `persons/{id}`,
  `persons/{id}/leads`) aceptan cualquier conversación o contacto por id o teléfono.
- **Una conversación no tiene «dueño» propio.** Se relaciona con un asesor por dos caminos:
  1. el **lead** (`whatsapp_conversations.lead_id` → `leads.user_id`, o los leads de su
     `person_id`);
  2. la **derivación** (`whatsapp_handoffs.user_id`): el agente deriva al asesor titular del
     inmueble consultado.
- Hay conversaciones **sin contacto ni lead** (números desconocidos, filtro «Sin asignar»).

---

## 3. Supuestos (corregir antes de seguir)

1. **«Sofopolis» es un usuario del CRM con rol Administrador.** No necesita una regla propia.
   *(Sin confirmar: no se respondió. Se mantiene como supuesto.)*
2. **El criterio es el ROL**, no el permiso de vista: `Administrador` y `Supervisores` ven
   el total; cualquier otro rol ve solo sus leads. *(Cambiado el 14/10: Fernando es
   supervisor y tiene que ver el total; con el permiso de vista habría que darle permiso
   «global» y la regla quedaría dispersa entre dos lugares.)*
   Todos los asesores tienen permiso de vista `individual`, así que el tablero y el kanban
   les muestran los mismos leads.
3. **Un asesor ve sus números completos**, no una versión recortada del tablero: las mismas
   tarjetas, calculadas sobre sus leads.
4. **«Leads de un asesor» = leads con `leads.user_id` = él** (el propietario del lead).
5. **El filtro se aplica en el servidor.** Ocultar tarjetas en la vista no alcanza: el
   endpoint `stats` y el export tienen que devolver datos ya filtrados.
6. Las tarjetas que no salen de leads se filtran por su dueño natural (ver D4).

---

## 4. Decisiones

| # | Decisión | Propuesta |
|---|---|---|
| D1 | Quién ve el total | Roles `Administrador` y `Supervisores`, en una lista de config (`tablero.roles_con_total`). |
| D2 | Qué ve un asesor | Las mismas tarjetas, calculadas solo sobre **sus** leads (`leads.user_id` = él). |
| D3 | Dónde se aplica | Un único punto de alcance en el reporting (`AbstractReporting`), que todas las tarjetas usen; no un `if` por tarjeta. |
| D4 | Tarjetas que no son de leads | **Inmuebles:** por los leads del asesor vinculados al inmueble. **Operación WhatsApp:** conversaciones de sus leads. **Top contactos:** contactos de sus leads. **Productos:** por sus leads. |
| D4b | Metas | **Oculta para todos** en pantalla (ya lo está). Además el endpoint `stats?type=metas` responde 403 a quien no tenga rol con total, para que no se pueda pedir por URL. |
| D5 | Comparativas entre asesores (ranking «por vendedor») | El asesor ve **solo su propia fila**. Administradores y supervisores ven **todas** las filas. |
| D6 | Filtro `user_id` en la URL | Un usuario no global solo puede pedir su propio id (o los de su grupo); cualquier otro se ignora y se usa su alcance. |
| D7 | Rótulos | **Según el rol:** el asesor ve «Mis …» (p. ej. «Mis prospectos»); administradores y supervisores ven los títulos actuales. |
| D8 | Export | La hoja resumen respeta el mismo alcance que el tablero en pantalla. |
| D9 | «Qué necesita atención ahora» | Los cuatro números y la salud de cartera se calculan sobre las conversaciones del asesor (D10). Administradores y supervisores ven los de la empresa, como hoy. |
| D10 | Qué conversaciones son de un asesor | **Las de sus leads**: el lead de la conversación (`lead_id`, o algún lead del contacto) tiene `user_id` = él. La derivación por sí sola **no** da acceso. *(Respondido 14/10.)* |
| D10b | Derivación cerrada | El asesor **la sigue viendo** después de que se cierra. Con D13 se cumple solo: el lead ya es suyo. *(Respondido 14/10.)* |
| D11 | Conversaciones sin contacto ni lead | Las ven **solo Sofopolis (id 1) y Danitza (id 3)** (usuarios puntuales, no un rol): lista de ids en config (`whatsapp.ven_sin_asignar_user_ids` = `[1, 3]`). *(Respondido 14/10.)* |
| D13 | A quién se deriva | **Al dueño del lead** (`leads.user_id`), no al titular del inmueble. Si el lead es de Fernando, se deriva a Fernando. El código del inmueble queda solo como referencia de la derivación. Sin lead: al asesor por defecto. *(Respondido 14/10.)* Así D10 y D14 encajan: el asignado siempre ve la conversación, porque el lead es suyo. |
| D15 | Quién cambia el asesor de un lead | **Solo `Administrador` y `Supervisores`.** Para un asesor, el campo «Asesor» del lead es de solo lectura en el formulario y en la ficha, y el servidor rechaza el cambio de `user_id` en todos los caminos (edición, edición en línea, actualización masiva). *(Respondido 14/10.)* |
| D16 | Un solo asesor por defecto | Hoy hay dos configs: `asesor_por_defecto` (guardia de derivaciones, `INMOBILIARIA_ASESOR_GUARDIA`, default 4 = Pedro) y `asesor_por_defecto_user_id` (leads nuevos, `LEADS_ASESOR_POR_DEFECTO_ID=6`). Se unifican en una sola, con valor **6 (Fernando)**: leads nuevos y derivaciones sin lead van a la misma persona. |
| D17 | Administradores sin restricciones | **Corregido 15/10:** el rol `Administrador` no tiene NINGUNA restricción en ningún módulo: ve todas las conversaciones (incluidas las sin asignar), puede tomar cualquier derivación y quedarse con ella al escribir. Regla única: `Alcance::sinRestriccion()` (`reporting.roles_sin_restriccion`). Prevalece sobre D11 y D14. |
| D14 | Tomar una derivación | **Solo el asesor asignado** la ve como tomable y la puede tomar; nadie más, tampoco supervisores ni administradores. El endpoint `handoff/claim` responde 403 a cualquier otro. *(Respondido 14/10.)* |
| D12 | Dónde se aplica en WhatsApp | Una única regla (`ConversacionVisible` o similar) usada por el listado **y por cada endpoint** que recibe una conversación, un contacto o un teléfono. Si no corresponde, el endpoint responde 404 (no 403, para no confirmar que existe). |

---

## 5. Criterios de éxito (verificables)

1. Logueado como **Asesor A**, cada tarjeta de `stats?type=…` devuelve números que coinciden
   con la suma de **sus** leads en el kanban para el mismo filtro de fecha y ciudad.
2. Logueado como **Asesor A**, ninguna respuesta de `stats` ni del export incluye leads,
   montos, conversaciones, inmuebles ni contactos de **Asesor B**.
3. Logueado como **Asesor A**, `stats?type=over-all&user_id=<id de B>` devuelve los datos
   de A, no los de B.
4. Logueado como **Administrador** o **Supervisor**, todas las tarjetas devuelven
   exactamente lo mismo que antes del cambio (sin regresiones).
5. Ningún tipo de tarjeta queda sin el alcance aplicado: un test recorre **todos** los
   `type` registrados en `DashboardController::$typeFunctions` y falla si alguno filtra
   datos ajenos.
6. Logueado como **Asesor A**, `stats?type=metas` responde 403.
7. Logueado como **Asesor A**, los títulos de las tarjetas de totales dicen «Mis …»; como
   Administrador o Supervisor, dicen lo mismo que hoy.
8. Logueado como **Asesor A**, los números de «Qué necesita atención ahora» coinciden con
   lo que ve en su inbox (p. ej. «Conversaciones sin responder» = sus conversaciones con
   mensajes sin leer).
9. Logueado como **Asesor A**, la lista del inbox no incluye ninguna conversación de
   Asesor B, y todos los endpoints de §2b responden 404 con el id o teléfono de una
   conversación de B. Un test recorre **todas** las rutas del módulo y falla si alguna no
   aplica la regla.
10. Logueado como **Administrador** o **Supervisor**, el inbox y la tarjeta muestran lo mismo
    que hoy, salvo las conversaciones sin contacto ni lead (D11).
11. Solo los usuarios de `whatsapp.ven_sin_asignar_user_ids` (Sofopolis y Danitza) ven las
    conversaciones sin contacto ni lead, en la lista y en el filtro «Sin asignar».
12. Una derivación asignada a Asesor A: solo A ve el botón «Tomar», y `handoff/claim` responde
    403 para cualquier otro usuario, incluidos administradores y supervisores.
13. Con un lead de Asesor A, el agente pide derivar citando un inmueble cuyo titular es B:
    la derivación queda asignada a **A**, no a B.
14. Logueado como **Asesor A**, el campo «Asesor» del lead no se puede cambiar, y un
    `PUT`/edición en línea/actualización masiva con otro `user_id` no lo cambia (403 o se
    ignora). Como Administrador o Supervisor, sí se puede.
15. Existe una sola config de asesor por defecto, y leads nuevos y derivaciones sin lead
    usan el mismo usuario (6).

---

## 6. Tech stack

Laravel 10 · Krayin CRM (módulos Concord en `packages/Webkul/`) · MySQL (prefijo `kl_`) ·
Pest · Blade + Vue en el front del tablero.

## 7. Comandos

```bash
php artisan test tests/Feature/Dashboard              # tests de esta funcionalidad
php artisan test                                      # suite completa antes de cerrar
./vendor/bin/pint packages/Webkul/Admin/src/Helpers   # estilo
cd packages/Webkul/Admin && npm run build             # si cambian rótulos o vistas del tablero
```

## 8. Estructura

```
packages/Webkul/Admin/src/Http/Controllers/DashboardController.php  → endpoint stats y export
packages/Webkul/Admin/src/Helpers/Dashboard.php                     → arma cada tarjeta
packages/Webkul/Admin/src/Helpers/Reporting/AbstractReporting.php   → punto único de alcance (D3)
packages/Webkul/Admin/src/Helpers/Reporting/{Lead,Person,Product,Metas,…}.php
packages/Webkul/Inmobiliaria/src/Helpers/Reporting/                 → tarjetas de inmuebles
packages/Webkul/Admin/src/Resources/views/dashboard/                → rótulos «Mis …» (D7)
packages/Webkul/Whatsapp/src/Helpers/Reporting/Operacion.php        → tarjeta «Qué necesita atención» (D9)
packages/Webkul/Whatsapp/src/Http/Controllers/{Chat,Inbox}Controller.php → listado y endpoints (D12)
tests/Feature/Dashboard/                                            → tests nuevos
```

## 9. Estilo de código

Seguir el del módulo: comentarios en español que explican el **por qué**, y el alcance
resuelto una sola vez. Ejemplo de la forma esperada:

```php
/**
 * Limita la consulta a los leads que el usuario actual puede sumar.
 *
 * Por ROL y no por permiso de vista: Fernando es supervisor y tiene que ver el
 * total sin que haya que darle permiso «global» en otro lado. La lista vive en
 * `tablero.roles_con_total`, el único lugar donde se decide quién ve la empresa.
 */
protected function alcance(Builder $query, string $columna = 'leads.user_id'): Builder
{
    $usuario = auth()->guard('user')->user();

    if (in_array($usuario?->role?->name, config('tablero.roles_con_total', []), true)) {
        return $query;
    }

    return $query->where($columna, $usuario?->id);
}
```

## 10. Estrategia de tests

- **Pest, feature tests** en `tests/Feature/Dashboard/`, con base de datos de prueba.
- Escenario fijo: 2 asesores (`individual`) y 1 administrador (`global`), cada asesor con
  leads en distintas etapas, montos y fechas.
- Un test **parametrizado por cada `type`** del controlador que verifica que el asesor no
  recibe datos ajenos (criterio 5).
- Tests de regresión del administrador: mismos números que antes (criterio 4).
- Test del `user_id` ajeno en la URL (criterio 3) y del export (criterio 2).

## 11. Límites

- **Siempre:** filtrar en el servidor; usar el mismo alcance que el kanban; correr la suite
  completa antes de dar por terminado.
- **Preguntar antes:** cambiar el `view_permission` o los roles de usuarios reales (incluido
  pasar a Fernando a `Supervisores`); quitar o renombrar tarjetas.
- **Nunca:** ocultar datos solo en el front dejando el endpoint abierto; cambiar los números
  que ve un administrador; ejecutar los tests contra la base de producción.

---

## 12. Preguntas — respondidas el 14/10/2026

1. **Sofopolis:** sin respuesta. Supuesto: es un usuario Administrador.
2. **Permiso de los asesores:** todos `individual`.
3. **Supervisores:** existen; es **Fernando**. ⚠️ Hoy el rol `Supervisores` tiene 0 usuarios
   activos: hay que asignárselo a Fernando (lo hace el cliente desde Configuración → Usuarios).
4. **Metas:** nadie la ve; queda oculta (D4b).
5. **Ranking:** el asesor ve solo su fila; administradores y supervisores ven todas (D5).
6. **Títulos:** según el rol (D7).

### Ampliación — respondidas el 14/10/2026

7. **Qué ve un asesor:** las conversaciones **de sus leads** (D10).
8. **Derivación cerrada:** **la sigue viendo** (D10b).
9. **Sin contacto ni lead:** solo **Sofopolis (id 1) y Danitza (id 3)** (D11).
10. **Tomar una derivación:** **solo el asignado** (D14).

### Usuarios (14/10/2026)

| ID | Usuario | Papel |
|---|---|---|
| 1 | Administrador (jmogro@sofopolis.com) | Sofopolis: ve el total y las conversaciones sin asignar |
| 2 | Agente (agente@sofopolis.com) | Usuario técnico del agente IA |
| 3 | Danitza Cuellar | Ve las conversaciones sin asignar |
| 4 | Pedro | Asesor |
| 5 | Eliana | Asesor |
| 6 | Fernando | Supervisor; asesor por defecto (`LEADS_ASESOR_POR_DEFECTO_ID=6`, ya configurado) |

### Cierre — 14/10/2026

- **D13:** se deriva al dueño del lead (respondido).
- **D15:** solo Administrador y Supervisores cambian el asesor (respondido).
- **Fernando:** ya tiene rol `Supervisores` (verificado en la base).
- **Sofopolis:** es el usuario 1, rol Administrador (verificado).
- **Roles verificados:** 1, 2, 3 Administrador (global) · 4 Pedro y 5 Eliana Asesor
  (individual) · 6 Fernando Supervisores (global).

Sin preguntas abiertas. Pendiente: aprobación para pasar a Plan.

## 13. Implementación — 14/10/2026

| Decisión | Dónde |
|---|---|
| D1–D3, D6 | `Admin/src/Helpers/Alcance.php` (única regla) · `DashboardController::aplicarAlcance()` (scope global en `Lead` + `user_id` forzado) · `reporting.roles_con_total` |
| D4 | `Reporting/Product.php`, `Reporting/Person.php`, `Inmobiliaria/.../Reporting/Inmueble.php` (salud de cartera = inmuebles de los que es titular) |
| D4b | `DashboardController::stats` → 403 para `metas` |
| D7 | `$personal` en el tablero; títulos «Mis …» en 9 tarjetas |
| D9 | `Whatsapp/src/Helpers/Reporting/Operacion.php` |
| D10–D12 | `Whatsapp/src/Services/ConversacionesVisibles.php`, aplicado en `ChatController` (lista, contacto, crear lead, vincular) y en todos los endpoints de `InboxController` (404 si no es visible) |
| D11 | `whatsapp.ven_sin_asignar_user_ids` = `[1, 3]` |
| D13 | `HandoffController::derivar()` → dueño del lead; sin lead → asesor por defecto |
| D14 | `InboxController::claimHandoff` (403), `HandoffRepository::autoderivar`, botón «Tomar» solo para el asignado |
| D15 | `LeadRepository::sinCambioDeAsesor()` (create y update) + campo de solo lectura en ficha y formulario |
| D16 | Una sola config `inmobiliaria.inmuebles.asesor_por_defecto` = 6 |

**Verificación (solo lectura, contra la base real):** las ~30 tarjetas ejecutadas como
Administrador, Supervisor, Pedro y Eliana; ninguna consulta del asesor toca `leads`,
`whatsapp_*`, `persons` o `inmuebles` sin filtro de usuario; inbox y apertura por id
probados por usuario; destino de derivación y permiso de tomar, probados.

**Sin tests automatizados:** el proyecto no tiene base de pruebas; los tests existentes
corren contra la base de producción (con transacciones). Queda pendiente configurar una
base de prueba (`.env.testing`) y escribir los tests de §10.
