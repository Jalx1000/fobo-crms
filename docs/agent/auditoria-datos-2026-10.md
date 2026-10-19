# Auditoría de datos — pedidos de WhatsApp (01/10/2026 – 15/10/2026)

Auditoría de solo lectura sobre producción, hecha el 15/10/2026. Se revisaron **los 43 pedidos**
creados desde el 01/10, uno por uno, comparando lo que quedó en el CRM con lo que el cliente
escribió en su conversación de WhatsApp.

Spec: `tasks/spec-auditoria-datos-agente.md`.

## Resumen

- **Pedidos confirmados por el agente: 11 de 13 están bien** en productos, cantidades y valor.
Los 2 errores son el mismo: el cliente pide **1 promo** y el agente registra **2**
  (#5280 y #5285).
- **Ciudad, asesor y canal: el agente no tuvo errores.** Las 34 ciudades que cargó coinciden
  con lo que dijo el cliente, y siempre asignó al encargado de esa ciudad.
- **Edad:** cuando el cliente la dijo, el agente la cargó bien en todos los casos. En 4
  conversaciones **no la preguntó**.
- **Lo que más se pierde es información de la conversación:** 21 pedidos quedan con la
  descripción genérica "Pedido en curso (WhatsApp)". 7 tienen el título "Cliente" aunque el
  agente sabía el nombre. La ficha de la persona conserva el nombre de perfil de WhatsApp
  ("Necromancer", ".", "#️⃣#️⃣#️⃣") en vez del nombre que dio el cliente.
- **Precios:** los 13 pedidos confirmados tienen el precio vigente **el día del pedido** para
  esa ciudad, contrastado con el historial del catálogo (el 09/10 se cambiaron unos 50 precios).
  Pero **2 de esos pedidos (#5276 y #5278) son de promos desactivadas desde el 28/09** (Promo
  Primavera y Promo Vinos Blancos), y entre el 01/10 y el 06/10 el agente cotizó Iconos a Bs 390–394, un precio
  que nunca existió en el catálogo.
- **Problemas que no son del agente:** 5 pedidos son de gente que no es cliente, hay
  inconsistencias de ciudad y asesor que vienen de ediciones manuales, y el catálogo permite
  variantes por ciudad que se superponen.

## Universo

| Grupo | Pedidos | Qué son |
|---|---|---|
| A. Pedido confirmado por el agente | 13 | El cliente eligió producto y cantidad y el agente registró el pedido |
| B. Conversación sin compra (agente) | 21 | El agente cargó ciudad, edad y nombre, pero el cliente no terminó de comprar |
| C. Sin intervención del agente | 9 | El CRM abrió el pedido y el cliente nunca dio la ciudad, o no es cliente |
| **Total** | **43** | |

Cómo nace un pedido: el CRM lo abre vacío apenas escribe un contacto nuevo. Queda como
"WhatsApp - <nombre de perfil>", "Sin ciudad", asignado a Daniel Escalante, y en el historial
aparece sin usuario. Cuando el cliente dice su ciudad, el **agente (usuario 13)** lo actualiza:
título, ciudad, asesor y descripción, y más adelante edad, valor y productos. Todo lo que hace
el agente queda en el historial a nombre del usuario 13.

## Resultado por campo

Se cuenta sobre los pedidos en los que el agente intervino (grupos A y B, 34 pedidos).

| Campo | Resultado | Detalle |
|---|---|---|
| Canal | ✅ 34/34 | Siempre "Whatsapp" |
| Ciudad (pipeline + atributo) | ✅ 34/34 | Coincide con lo que dijo el cliente; pipeline y atributo `Ciudad` iguales |
| Asesor según la ciudad | ✅ 34/34 | Siempre el encargado de esa ciudad |
| Edad del cliente | ⚠️ 26 bien, 4 no la preguntó, 4 el cliente no respondió | No la preguntó en #3807, #4094, #5286 y #5534 |
| Productos y cantidades | ⚠️ 11/13 en el grupo A | #5280 y #5285: 1 promo registrada como 2 |
| Valor del pedido | ✅ 13/13 = suma de los productos | Los 2 casos de arriba están inflados (Bs 180 en vez de 90) |
| Precio unitario | ✅ 13/13 = precio de catálogo **a la fecha del pedido** | Contrastado con el historial de precios y con la variante de la ciudad. Ver hallazgo 6 por los productos desactivados |
| Descripción | ⚠️ 13/34 útil | El grupo A tiene un resumen estructurado. Los 21 del grupo B quedan como "Pedido en curso (WhatsApp)" |

## Hallazgos para el equipo del agente

### 1. "1 promo" se registra como 2 promos — 2 casos, uno con reclamo del cliente

La promo trae 2 botellas, y el agente confunde la cantidad de promos con la de botellas.

| Lead | Teléfono | El cliente dijo | Quedó registrado |
|---|---|---|---|
| #5280 | 59165172498 | "Una por favor" | 2 × Promo Mujer Boliviana = Bs 180 |
| #5285 | 59172083194 | "1 promo por favor". Después reclamó: "Es solo 1 promo, no 2" | 2 × Promo Mujer Boliviana = Bs 180 |

En #5285 el agente le respondió "mantenemos el pedido original con 1 promo", pero **el pedido
nunca se corrigió**: sigue en 2 × 90 = Bs 180. En #3806 pasó lo mismo con una botella (el
agente mostró 2 × 420 = Bs 840); ahí el cliente lo corrigió antes de confirmar y el registro
final quedó bien.

**Regla esperada:** la cantidad registrada es la cantidad de **promos** que dijo el cliente. Si
el cliente corrige después de confirmar, hay que actualizar el pedido; no alcanza con
responderle en el chat.

### 2. Edad no preguntada — 4 casos

| Lead | Teléfono | Qué pasó |
|---|---|---|
| #3807 | 59170831226 | Después de la ciudad pasó directo a las promos |
| #4094 | 59175808544 | Ídem |
| #5286 | 59170733117 | Registró el pedido sin edad |
| #5534 | 59170692021 | Registró el pedido sin edad |

En #5286 y #5534 el cliente ya venía con nombre de perfil y el agente se salteó la pregunta
"nombre y edad" entera. **Regla esperada:** la edad es obligatoria antes de registrar un pedido
de alcohol, aunque el nombre ya se conozca.

### 3. Título "Pedido Club del Vino - Cliente" aunque el nombre se conoce — 7 casos

#3794, #3801, #3807, #4093, #4094, #5273, #5284. En todos el agente saluda al cliente por su
nombre (por ejemplo "¡Hola Jose Luis!"), pero si el cliente no lo vuelve a escribir, el título
queda como "Cliente". **Regla esperada:** si el cliente no escribe su nombre, usar el nombre de
perfil antes que "Cliente".

### 4. La descripción no guarda lo que pidió el cliente — 21 casos

Los pedidos sin compra quedan como "Pedido en curso (WhatsApp)", y el asesor que los retoma no
sabe qué buscaba el cliente. Ejemplos que se pierden:

| Lead | Teléfono | Lo que dijo el cliente |
|---|---|---|
| #3801 | 59178171751 | Cotización para una boda el 11/09/2027, 180 personas |
| #4093 | 59170655181 | Stelar Ugni Blanc para canastones navideños |
| #3793 | 59177358778 | Preguntó por Stelar, a Bs 70 |
| #3807 | 59170831226 | Interés en el Pack Tannat + Copa / Cautivo |
| #3803 | 59178922902 | Buscaba "Kohlberg clásico" / "Kohlberg tinto" y no lo encontró |

**Regla esperada:** al cerrar la conversación sin compra, actualizar la descripción con el
interés del cliente (producto, cantidad, ocasión).

### 5. La ficha de la persona no recibe el nombre real — 12 casos

El nombre que da el cliente va al título del pedido, pero la persona conserva el nombre de
perfil de WhatsApp:

| Lead | Nombre en la ficha | Nombre que dio el cliente |
|---|---|---|
| #3792 | LA DIFFERENZA | Cristian |
| #3796 | Nomen Nescio | Cristian |
| #3797 | Necromancer | David Andrés Valero |
| #3800 | R | Juan |
| #3806 | . | Alvaro Cortez |
| #3809 | 💐Ivisita 👑 | Ivania Apaza |
| #5275 | 🪐✨ | Eilin Reyes |
| #5279 | Downtown Hotel DT Brew House | Claudia Arteaga |
| #5282 | #️⃣#️⃣#️⃣ | Juan Carlos |

También #4969, #5281 y #5287, con diferencias menores ("Eddy G." en vez de "Eddy Garcia").

### 6. Precios y disponibilidad: el agente no usa el catálogo vigente del CRM

**Cómo se verificó.** El catálogo tiene variantes del mismo producto por ciudad, con precios
distintos (por ejemplo, Semidulce Tinto #4 a Bs 35 y #122 a Bs 32). El 09/10, entre las 12:00
y las 14:20, se cambiaron unos 50 precios y ciudades. Cada línea de pedido se comparó con el
**historial del producto** (precio, ciudades y estado activo a la fecha del pedido), no con el
precio de hoy.

**Lo que está bien.** Los 13 pedidos confirmados tienen el precio que correspondía ese día y en
esa ciudad, y es el mismo precio que el agente le dijo al cliente. Ejemplo: #4969 (Cochabamba,
10/10) quedó a Bs 32. La variante de Bs 35 para Cochabamba se había desactivado el 09/10 a las
12:26, así que Bs 32 era el precio correcto.

**Lo que está mal:**

a) **Promos desactivadas desde el 28/09 que se vendieron igual:**

| Lead | Teléfono | Producto | Estado en el catálogo | Qué pasó |
|---|---|---|---|---|
| #5276 | 59176284904 | Promo Primavera, Bs 75 (11/10) | Inactiva desde 28/09 12:15 | El agente le dijo "sigue activa" |
| #5278 | 59176284904 | Promo Vinos Blancos, Bs 135 (12/10) | Inactiva desde 28/09 12:16 | Se registró y figura **entregado** |
| #3798 | 59170182026 | Promo Vinos Blancos (09/10) | Inactiva | La vendió un asesor; el pedido quedó cancelado y sin productos |

El agente además se contradice: el 06/10 (#3804) y el 08/10 (#4094) dijo "no tenemos Promo
Primavera", y el 11/10 (#5276) le dijo a otra clienta que sí estaba activa. Un asesor también
avisó el 08/10 que estaba "agotada" (#3808).

b) **Precios cotizados que no existen en el catálogo (01/10 – 06/10):**

| Lead | Ciudad | Fecha | El agente cotizó | Catálogo del CRM ese día |
|---|---|---|---|---|
| #3796, #3800 | Cochabamba | 02-03/10 | Icono Tempranillo Bs 394 | Bs 420, y **solo para Tarija** (01/10 → 06/10) |
| #3797, #3799 | La Paz | 02-03/10 | Icono Tempranillo Bs 394 | Ídem |
| #3803 | Santa Cruz | 05/10 | Icono Tempranillo Bs 390 | Ídem |
| #3804 | Cochabamba | 06/10 | Icono Malbec Bs 393 | Ídem |
| #3799 | La Paz | 03/10 | Promo Primavera Bs 150 | Bs 75, e **inactiva** |

En Tarija (#3792, 01/10) el agente cotizó Bs 420, que era correcto. Ninguna de estas
cotizaciones terminó en pedido, así que no hay registros con precio equivocado. Pero los
clientes recibieron precios que el CRM no tenía, y productos que en su ciudad no estaban
habilitados.

**Regla esperada:** el agente cotiza y vende solo productos **activos** y habilitados para la
ciudad del cliente, al precio del catálogo **en ese momento**. Si cachea el catálogo, tiene
que refrescarlo cuando cambia, porque el 09/10 cambiaron unos 50 precios en dos horas.

### 6b. El producto del mensaje de entrada no es un pedido

Muchos clientes llegan con el texto del anuncio ("Hola quiero pedir una Promo Mujer
Boliviana"). Hoy el agente **no** registra productos sin confirmación, y está bien. Ver el
hallazgo 7: esos productos los cargó el equipo a mano el 15/10.

## Hallazgos para el CRM (no son del agente)

### 7. Carga manual del equipo el 15/10 — 4 pedidos (no es del agente)

El 15/10 entre las 09:22 y las 09:37, el equipo (no el agente) agregó a mano un producto y
su valor a #5273, #3795, #3794 y #5533, según el producto del anuncio con el que llegó el
cliente. En ninguno de los 4 el cliente confirmó una compra en el chat, así que **estos
pedidos no se cuentan como errores del agente.** Para el CRM quedan dos observaciones:

- La carga no dejó actividad en el historial del pedido, así que en el CRM no se sabe quién
  la hizo ni cuándo.
- Promo Tannat y Promo Primavera están **inactivas** en el catálogo desde el 28/09 (ver
  hallazgo 6).

### 8. Ciudad y asesor inconsistentes después de ediciones manuales — 3 casos

| Lead | Problema |
|---|---|
| #5283 | La ciudad es Cochabamba (el cliente lo dijo), pero el asesor sigue siendo **Daniel Escalante** (Tarija) en vez de Gabriel Muñoz. Al cambiar la ciudad no se reasignó el asesor |
| #3795 | Pipeline **Tarija**, atributo `Ciudad` = **Sin ciudad**. El cliente nunca dijo su ciudad. Una admin puso "Tarija" y después un asesor la cambió a "Sin ciudad", pero el pipeline no se movió |
| #5277 | Igual que #3795: pipeline Tarija, atributo "Sin ciudad", y el cliente nunca dijo la ciudad |

En #5277 y #5283 una administradora puso "Tarija" a las 23:01 sin que el cliente lo hubiera
dicho. En #5283 el cliente era de Cochabamba.

### 9. Ciudad de la persona con valor inválido "0" — 3 casos

#3794, #5284 y #5533: la persona tiene `cliente_ciudad = 0`, que no es una ciudad válida,
mientras el pedido sí tiene ciudad (Potosí, Cochabamba y La Paz).

### 10. Pedidos de gente que no es cliente — 5 casos

| Lead | Quién es |
|---|---|
| #3805 | Natural English: mensaje automático de bienvenida |
| #4171 | Natural English: soporte |
| #5297 | "Coordinación Santa Cruz": recordatorio de una reserva |
| #5274 | Pregunta por una sesión de fotos en el viñedo (quedó en Otros Servicios) |
| #5532 | Pregunta por el restaurante y la cata (derivado a un asesor) |

Los 3 primeros son mensajes equivocados o automáticos. Igual abren un pedido en "No atendido"
y suman a las métricas de pedidos sin atender.

### 11. La conversación apunta al pedido viejo — Claudia (59176284904)

La conversación #941 sigue vinculada al pedido #1699 de septiembre. Los pedidos nuevos #5276
(Promo Primavera) y #5278 (Promo Vinos Blancos) no están vinculados a la conversación. Desde
el chat no se llega a los pedidos actuales del cliente.

### 12. Datos de prueba en producción

#4345, "Sofooplis" (59176616013): pedido de prueba (2 × Pack Flamant Rosé + Copa, Bs 170, en
Cancelados), con mensajes como "Hola mundo desde la app". Hay que excluirlo de las métricas o
borrarlo.

### 13. Catálogo con variantes por ciudad sin reglas que eviten superposiciones

Cada producto existe en 2 o 3 filas con el mismo nombre y distinto precio según la ciudad
(Tinto Clásico #1/#106/#107 a Bs 22/24/25; Stelar #14/#114 a Bs 70/74). Nada impide que dos
variantes activas cubran la misma ciudad con precios distintos. Hasta el 09/10, Semidulce Tinto
#4 (Bs 35) y #122 (Bs 32) no se superponían; ese día se ordenó a mano. Un error en la lista de
ciudades de una variante cambia el precio que se le cobra al cliente sin que nada lo alerte.

### 14. Errores de carga de los asesores

- #3798 (59170182026): el cliente **retiró su pedido** Promo Vinos Blancos el 09/10,
  coordinado por un asesor en el chat, pero el pedido está en **Pedidos cancelados**, con
  valor 0 y sin productos.
- #3802 y #3809 recibieron la plantilla "su pedido ya está listo para ser recogido" sin tener
  pedido. En #3809 el cliente pidió la dirección y el pedido quedó como "Cliente sin interés".

## Medidas acordadas (15/10/2026)

**Para el equipo del agente:**
1. Consultar el catálogo al CRM en cada conversación (o con una copia de pocos minutos). No
   ofrecer ni vender productos que el CRM no devuelva como **activos** para la ciudad del cliente.
2. La cantidad registrada es la cantidad de **promos** que dijo el cliente, no de botellas.
3. Si el cliente corrige después de confirmar, **actualizar el pedido**.
4. La edad es obligatoria antes de registrar un pedido.
5. Título con el nombre de perfil si el cliente no escribe su nombre; nunca "Cliente".
6. Al cerrar sin compra, guardar en la descripción qué buscaba el cliente.
7. Mandar el nombre real también para la ficha de la persona.
8. Responder sobre disponibilidad solo con lo que devuelve el CRM en ese momento.

**Para el CRM:**
1. Arreglar `GET /api/productos/por-ciudad-sucursal`: hoy filtra la ciudad por
   `integer_value`, que está vacío en los 61 productos (el dato está en `text_value`), y no
   excluye los productos inactivos.
2. Rechazar al registrar pedidos con productos inactivos o no habilitados para su ciudad.
3. El precio unitario lo pone el CRM desde el catálogo vigente.
4. Evitar dos variantes activas del mismo producto para la misma ciudad.
5. Toda carga (manual, script o importación) deja registro en el historial.
6. Al cambiar la ciudad se reasigna el encargado, y el atributo Ciudad y el pipeline no pueden
   quedar distintos.
7. Validar `cliente_ciudad` (no aceptar "0").
8. Pedido confirmado: exige edad, al menos un producto, y valor = suma de los productos.
9. **Seguimiento y cierre automático:**
   - Si le escribimos al cliente y no contestó, a las 16 h de su último mensaje (con 8 h de
     ventana restantes) Sofía le manda un recordatorio de texto fijo, no una plantilla y no en
     mayúsculas. Las plantillas de reapertura tuvieron 0 respuestas de 36 en 30 días.
   - Si sigue sin contestar 72 h después de nuestro último mensaje, el pedido pasa a "Cliente
     sin interés" en su propio pipeline. Solo aplica a "No atendido" y "Cliente contactado",
     solo hacia adelante, y si el cliente vuelve a escribir el pedido se reabre en "Cliente
     contactado".
   - Spec: `tasks/spec-sin-respuesta-3-dias.md`.

**Descartado:** la revisión diaria automática de datos. Se mantiene que **todo contacto tiene
pedido**: los que no son clientes los descarta el equipo de Kohlberg a mano, o los cierra la
regla de 3 días.

## Anexo: los 43 pedidos

Grupos: A = pedido confirmado por el agente, B = conversación sin compra, C = sin intervención
del agente.

| Lead | Grupo | Teléfono | Resultado |
|---|---|---|---|
| 3792 | B | 59171191076 | OK · ficha "LA DIFFERENZA" |
| 3793 | B | 59177358778 | OK · descripción sin el interés (Stelar) |
| 3794 | B | 59178733404 | Producto cargado a mano el 15/10 (equipo) · título "Cliente" · cliente_ciudad=0 |
| 3795 | C | 59175206981 | Producto cargado a mano el 15/10 (equipo) · pipeline ≠ atributo Ciudad |
| 3796 | B | 59177968448 | Cotizó Icono a Bs 394 (no existe en el catálogo) · ficha "Nomen Nescio" |
| 3797 | B | 59170175137 | OK · ficha "Necromancer" |
| 3798 | B | 59170182026 | Venta real cargada como cancelada (asesor) |
| 3799 | B | 59170192296 | Cotizó Icono a Bs 394 y Promo Primavera a Bs 150 (inactiva) |
| 3800 | B | 59177445221 | OK · ficha "R" |
| 3801 | B | 59178171751 | Título "Cliente" · se perdió la cotización de boda (180 personas) |
| 3802 | B | 59171527691 | OK · plantilla de "pedido listo" sin pedido |
| 3803 | B | 59178922902 | OK · descripción sin el interés |
| 3804 | B | 59172288295 | OK |
| 3805 | C | 59170957189 | No es cliente (Natural English) |
| 3806 | A | 59168759493 | OK · ficha "." |
| 3807 | B | 59170831226 | Edad no preguntada · título "Cliente" |
| 3808 | B | 59171074325 | OK |
| 3809 | B | 59170163429 | OK · ficha "💐Ivisita 👑" · plantilla de "pedido listo" sin pedido |
| 4093 | B | 59170655181 | Título "Cliente" · se perdió el interés (canastones) |
| 4094 | B | 59175808544 | Edad no preguntada · título "Cliente" |
| 4171 | C | 59160228302 | No es cliente (Natural English) |
| 4345 | A | 59176616013 | Pedido de prueba (Sofooplis) |
| 4969 | A | 59170394918 | OK |
| 5273 | B | 59175121544 | Producto cargado a mano el 15/10 (equipo) · título "Cliente" |
| 5274 | C | 59169326121 | No es cliente (sesión de fotos) |
| 5275 | B | 59168639836 | OK · ficha "🪐✨" |
| 5276 | A | 59176284904 | Promo Primavera **inactiva** vendida · conversación vinculada al pedido viejo |
| 5277 | C | 59177107019 | Pipeline Tarija ≠ atributo "Sin ciudad"; ciudad no dicha |
| 5278 | A | 59176284904 | Promo Vinos Blancos **inactiva** vendida (entregada) · conversación vinculada al pedido viejo |
| 5279 | A | 59177747032 | OK · ficha "Downtown Hotel DT Brew House" |
| 5280 | A | 59165172498 | **1 promo registrada como 2 (Bs 180 en vez de 90)** |
| 5281 | A | 59179154292 | OK |
| 5282 | A | 59172622648 | OK · ficha "#️⃣#️⃣#️⃣" |
| 5283 | C | 59172649914 | Asesor de Tarija para un cliente de Cochabamba |
| 5284 | B | 59175253738 | Título "Cliente" · cliente_ciudad=0 |
| 5285 | A | 59172083194 | **1 promo registrada como 2, con reclamo del cliente sin corregir** |
| 5286 | A | 59170733117 | Edad no preguntada |
| 5287 | A | 59173252448 | OK |
| 5296 | C | 59160399244 | Sin ciudad (no respondió) |
| 5297 | C | 59179894343 | No es cliente (recordatorio de reserva) |
| 5532 | C | 59175710113 | Consulta de restaurante y cata (derivada) |
| 5533 | B | 59170584172 | Producto cargado a mano el 15/10 (equipo) · cliente_ciudad=0 |
| 5534 | A | 59170692021 | Edad no preguntada |
