# Para el agente — asignación por zona y clientes conocidos

Dos problemas a resolver entre el CRM y el agente:

1. Los pedidos no quedan asignados al distribuidor que reparte en esa zona.
2. El agente vuelve a preguntar todo el flujo a un cliente que ya compró antes.

Este documento tiene las cuatro tareas del agente y el prompt listo para pegar.

> **Estado:** las tareas A1 y A2 se pueden aplicar ya. Las A3 y A4 dependen de un
> cambio del CRM que todavía no está desplegado (ver *Orden de aplicación* al final).

---

## El diagnóstico, en dos líneas

**La asignación.** El CRM asigna el pedido a quien esté autenticado. Cuando el pedido
lo crea el agente por la API, el autenticado es el **dueño del token** — un
administrador. Por eso los pedidos quedaban a nombre de un admin y no del
distribuidor. La ciudad del pedido siempre se supo; lo que faltaba era usarla.

**El flujo repetido.** El agente no se olvida de los datos del cliente: **nunca los
recibió**. Hoy el evento le manda sólo esto:

```json
"contact": { "phone": "...", "name": "...", "person_id": 12, "lead_id": 34 }
```

Sin domicilio, sin ciudad, sin saldo de bidones y sin los pedidos que ya tiene
abiertos. Por eso pedirle en el prompt que "recuerde" no alcanza: primero el CRM
tiene que mandárselo.

---

## Las cuatro tareas

### A1 · Nunca mandar `user_id` al crear un pedido

Si el agente manda ese campo, **pisa la asignación por zona** y vuelve el problema.
El asesor lo decide el CRM a partir de la ciudad.

Es el cambio más importante de los cuatro y no depende de nada: se aplica ya.

### A2 · Mandar siempre la ciudad

La ciudad (`lead_pipeline_id`) es lo que determina quién reparte. Un pedido sin
ciudad cae en "Sin ciudad", que no tiene distribuidor asignado, y queda sin dueño
hasta que alguien lo corrija a mano.

Si el cliente es nuevo y no se sabe la zona, hay que preguntarla. No es un dato
opcional: es el que decide si va Chaile o Marcelo.

### A3 · Si el cliente ya es conocido, no rehacer el alta

Requiere el cambio del CRM. Cuando llegue, el evento va a traer un bloque `cliente`
con el domicilio, la ciudad, el saldo de envases y el último pedido. Con eso el
agente confirma en una línea y pregunta sólo la cantidad.

### A4 · Mirar los pedidos pendientes antes de abrir otro

Requiere el mismo cambio. Hoy el agente ve un solo pedido —el de la conversación— y
no sabe si la persona tiene otros sin entregar, así que abre duplicados.

---

## El prompt

Esto es lo que hay que agregarle a las instrucciones del agente.

```text
ASIGNACIÓN Y CLIENTES CONOCIDOS

=== 1. AL CREAR UN PEDIDO ===

NUNCA mandes el campo `user_id` (Asesor Asignado). Ni vacío, ni con un valor.
El CRM asigna solo al distribuidor que reparte en esa ciudad. Si vos lo mandás,
pisás esa asignación y el pedido queda a nombre de quien no corresponde.

SIEMPRE mandá la ciudad del pedido (`lead_pipeline_id`). Es lo que decide quién
sale a repartir:
    Palpalá                → Chaile
    San Salvador de Jujuy  → Marcelo Aramayo
    Barrio Alto Comedero   → Distribuidor Comedero

No uses esa tabla para asignar vos: es para que entiendas por qué la ciudad es
obligatoria. Vos mandás la ciudad, el CRM elige la persona.

Si es un cliente nuevo y no sabés de qué zona es, PREGUNTALE. Un pedido sin
ciudad queda sin distribuidor y no lo ve nadie.

=== 2. SI YA CONOCEMOS AL CLIENTE ===

El evento trae un bloque `cliente`. Cuando `cliente.es_conocido` es true, esta
persona ya compró antes y tenemos sus datos. NO rehagas el alta.

Lo que NO hay que volver a preguntar si ya viene en el bloque:
  - el domicilio (`cliente.domicilio`)
  - la ciudad (`cliente.ciudad`)
  - el nombre

Lo ÚNICO que hay que preguntar es qué y cuánto quiere.

Confirmá el domicilio en una línea, no lo pidas de nuevo:

    "¡Hola Marcela! ¿Te llevamos a Belgrano 100 (portón verde) como siempre?
     ¿Cuántos botellones te dejamos?"

Recién si dice que se mudó, pedile el nuevo.

Si `cliente.es_conocido` es false, ahí sí hacé el alta completa como siempre.

Datos extra que te sirven para la conversación:
  - `cliente.bidones_en_su_casa`: cuántos envases tiene sin devolver. Si son
    varios, podés recordarle que los deje listos.
  - `cliente.ultimo_pedido`: qué pidió la última vez. Sirve para ofrecer "lo
    mismo de siempre" en vez de hacerle elegir del catálogo entero.

=== 3. PEDIDOS QUE YA TIENE ABIERTOS ===

El evento trae `pedidos_pendientes`: los pedidos de esta persona que todavía no
se entregaron.

Si la lista NO está vacía y el cliente pide algo, NO abras un pedido nuevo sin
preguntar. Mostrale lo que ya tiene y preguntá:

    "Ya tenés un pedido pendiente de 2 botellones para hoy. ¿Querés que le sume
     estos 3, o lo dejamos como un pedido aparte?"

  - Si dice "sumalo"     → actualizá el pedido pendiente (PUT), mandando los
                            productos que ya tenía MÁS los nuevos. El PUT
                            reemplaza la lista: lo que no mandes se borra.
  - Si dice "aparte"     → creá uno nuevo (POST).

Si `pedidos_pendientes` está vacía, creá el pedido nuevo directamente.

=== 4. LO QUE NO CAMBIA ===

Todo lo demás sigue igual: las reglas de productos por sucursal, cómo guardar la
ubicación, y el recordatorio de recompra.
```

---

## Orden de aplicación

**Ahora:** la sección 1 del prompt (tareas A1 y A2). No depende del CRM y es la que
arregla la asignación de pedidos.

**Después del cambio del CRM:** las secciones 2 y 3 (tareas A3 y A4). Aplicarlas
antes es contraproducente: el agente va a buscar `cliente.es_conocido` y
`pedidos_pendientes`, no los va a encontrar, y en el mejor caso los ignora — en el
peor, da por nuevo a todo cliente conocido.

Cuando el bloque esté desplegado se avisa, y recién ahí se pega el prompt completo.

---

## Lo que hace el CRM de su lado

Para que quede claro el reparto de responsabilidades:

| | |
|---|---|
| Elegir el distribuidor según la ciudad | **CRM** |
| Mandar la ciudad del pedido | **Agente** |
| Que "Asesor Asignado" liste sólo distribuidores | **CRM** |
| Mandar los datos del cliente en el evento | **CRM** |
| Usar esos datos para no repreguntar | **Agente** |

Reglas con las que el CRM reasigna un pedido:

```
sin asesor                      → asigna
el asesor no es distribuidor    → reasigna   (el caso del token del agente)
es distribuidor de otra ciudad  → reasigna
es el distribuidor de esta      → no toca    (respeta la asignación manual)
```
