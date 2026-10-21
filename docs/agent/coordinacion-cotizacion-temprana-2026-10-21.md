# Mensaje al equipo del agente (CRM Imprimir)

**Asunto: que cada cliente que escribe tenga persona, pedido y cotización. ¿Cuál es la mejor forma?**

Hola equipo. Queremos que cada conversación de un cliente deje en el CRM
**persona, pedido y cotización** sin que un asesor tenga que armarlos. Les
pasamos cómo está hoy, las opciones que vemos y las preguntas que solo
ustedes pueden contestar. **No hay nada aplicado todavía**; elegimos juntos.

## 1. Cómo está hoy (datos de los últimos 14 días)

| Qué | Quién lo crea | Cuándo | Cobertura |
|---|---|---|---|
| Persona | CRM | Al entrar el primer mensaje (por teléfono; si no existe, la crea) | 491 de 491 conversaciones |
| Pedido | CRM | Al entrar el primer mensaje, en el tablero de la ciudad o «Sin ciudad» | 491 de 491 |
| Cotización | Agente, con `POST /conversations/{id}/cotizacion` | Recién en el PASO 5, con producto, variante y cantidad | **90 de 489 pedidos (18 %)** |

- Persona y pedido ya están resueltos del lado del CRM. **Lo que falta es la
  cotización.**
- Hoy `/cotizacion` exige `sku` y `cantidad`. Si faltan ejes de precio
  responde 422 («Faltan datos para cotizar»). Si la conversación todavía no
  tiene persona, también 422.
- Si la charla se corta antes del PASO 5 (el cliente deja de contestar en la
  ciudad, la variante o los datos de empresa), **no queda ninguna
  cotización**, aunque ya sepamos qué producto quería.

## 2. Opciones

### A. Cotización vacía con cada mensaje nuevo (la crea el CRM)

- Cotización sin productos al crearse el pedido.
- **No la recomendamos.** Serían unas 400 cotizaciones vacías cada dos
  semanas («hola», consultas sueltas, gente que no sigue). Llenarían el
  listado de Cotizaciones, inflarían las métricas del tablero y quedarían
  cotizaciones que se pueden imprimir o enviar sin nada adentro.

### B. Borrador desde que se sabe el producto, completado paso a paso (recomendada)

- **Paso 1 o 2 del flujo:** apenas el agente sabe el producto, llama a
  `crear_cotizacion` en modo borrador, solo con `sku` (y `ciudad` si ya la
  tiene).
- **Cada dato nuevo** (variante, cantidad, NIT, empresa, ciudad): llama a una
  tool nueva, `actualizar_cotizacion`.
- **PASO 5:** la cotización ya existe. El agente la marca como lista para el
  asesor, en vez de crearla.

Lo que pondría el CRM:

- **Estado «borrador»:** no aparece en el listado de Cotizaciones ni en las
  métricas, no se puede enviar ni imprimir, y sí se ve en la ficha del pedido
  como «en curso».
- **Un solo borrador por pedido y producto.** Si el agente vuelve a llamar a
  crear con el mismo producto, el CRM devuelve el que ya existe (sin
  duplicados ni choques).
- **El precio lo sigue poniendo el CRM** con la lista de precios, recién
  cuando tiene variante y cantidad. El agente nunca manda montos.
- **Abandono:** un borrador sin cambios en 24 h pasa a «pendiente del asesor»
  con lo que haya, para que nadie pierda la demanda.

### C. Sin borrador: llamar antes a la tool actual

- El agente llama a `/cotizacion` apenas tiene producto, variante y cantidad
  (fin del PASO 3), sin esperar los datos de empresa del PASO 4. Los datos de
  empresa se agregan después, con una actualización.
- Es menos trabajo, pero lo que se corta en los PASOS 1 a 3 sigue sin
  cotización.

### D. Solo del lado del CRM

- El CRM arma la cotización cuando el pedido pasa a «Solicitud recibida»,
  con lo que sepa del pedido.
- No hay que tocar el agente, pero sin el producto registrado por el agente
  la cotización sale vacía. Es casi la opción A.

## 3. Lo que necesitamos que nos digan

1. **¿En qué paso tienen el producto con seguridad?** ¿Ya en el PASO 1 (el
   cliente toca «Bolsas»), o recién cuando lo confirma?
2. **¿Pueden llamar tools en medio del flujo**, en cada paso, sin que el
   cliente lo note (sin demoras ni mensajes de más)? ¿Cuánto tarda hoy una
   llamada al CRM desde Railway?
3. **¿Cómo guardan el estado entre mensajes?** Para actualizar necesitan
   recordar el `id` de la cotización que devolvió el CRM, o podemos resolverla
   nosotros por conversación más producto. ¿Qué les resulta más fácil?
4. **Varios productos en una charla** (hoy pasa: el 19/10 hubo 4 cotizaciones
   en el pedido #1075). ¿Prefieren una cotización por producto (como hoy) o
   una sola con varias líneas?
5. **Si el cliente cambia de producto a mitad de camino** («mejor hules»):
   ¿descartamos el borrador anterior o lo dejamos?
6. **¿Les sirve que el CRM resuelva la cotización por conversación**, sin que
   ustedes manden el `id`? Así `actualizar_cotizacion` solo lleva los datos
   nuevos.
7. **¿Qué opción prefieren: B, C u otra?** Si ven algo mejor desde su lado,
   lo hablamos.

## 4. Próximos pasos

- Con sus respuestas cerramos el contrato de los endpoints (pedido, respuesta
  y errores) y se los pasamos antes de programar.
- Del lado del CRM, los endpoints nuevos son compatibles con lo actual:
  `/cotizacion` sigue funcionando igual hasta que ustedes cambien el flujo.

Gracias.
