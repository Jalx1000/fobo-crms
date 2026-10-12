# TODO — Agente de IA de Imprimir (auditoría del 12/10/2026)

> **Para:** equipo que mantiene el agente (Python · LangChain / LangGraph).
> **Base:** 303 conversaciones reales y ~2.400 mensajes del 02/10 al 12/10/2026 (301 por Kommo y 2 por Messenger).
> **Lo que anda bien y no hay que romper:** el agente responde en 12 s de mediana y 14 s en el p90, con mensajes cortos (p50 de 122 caracteres) y buen uso de menús.

Los cambios del lado del CRM van en otro documento (`auditoria-2026-10-12-todo-crm.md`). Algunos puntos de abajo **dependen** de esos cambios, y está marcado en cada uno.

---

## P1 — Responder primero lo que pregunta el cliente

**Problema.** El 79 % de las conversaciones empieza con el texto prellenado de un anuncio:

| Primer mensaje | Conversaciones |
|---|---|
| ¿Cuáles son los tamaños disponibles del Stretch Film? | 102 |
| ¿Cuánto cuesta la bolsa XXL? | 81 |
| ¿Cuánto cuesta la bolsa de 140L? | 54 |

El agente no contesta esa pregunta: responde con la presentación ("Nuestras bolsas Magia Verde son de material reciclado…") y pide ciudad, uso y cantidad. De las 303 conversaciones, **101 terminan sin un segundo mensaje del cliente**.

Ejemplo:
```
Cliente: Buenas noches, precio y medidas de las bolsas
Agente:  ¡Hola! Gracias por escribirnos 👋 Nuestras bolsas Magia Verde son de material
         reciclado, resistente y multiuso. ¿De qué ciudad nos escribe? [lista]
Agente:  ¿Para qué uso la necesita? [lista]
```

**Qué hacer.**
- [ ] Si el primer mensaje trae una pregunta concreta (tamaños, precio), la primera respuesta la contesta con datos reales de `ficha_producto` / `precio_producto`, aunque sea de forma parcial ("La de 140 L va desde X Bs el pack de 10, según la cantidad…"). Después se piden los datos que faltan.
- [ ] La presentación de la marca va como máximo una vez por conversación y nunca reemplaza a la respuesta.
- [ ] Pedir solo los datos que el precio necesita **para ese producto**: `faltan` en la respuesta de `precio_producto` dice exactamente cuáles son.

**Aceptación.** Ante "¿Cuáles son los tamaños disponibles del Stretch Film?", la primera respuesta nombra las dos presentaciones (Manual 4,7 kg y Automático 15,7 kg).

---

## P2 — Traducir lo que dice el cliente a los valores del catálogo

**Problema.** El agente llamó a `precio_producto` 93 veces y **ninguna respuesta suya incluye un precio en Bs**. Una parte se debe a que el agente manda valores que el catálogo no conoce:

| El cliente o el anuncio dice | El catálogo espera |
|---|---|
| "XXL" | `Tamaño`: `35 L`, `50 L`, `75 L`, `140 L`, `200 L` (XXL no existe; confirmar con Comercial si es 140 L o 200 L) |
| "140L", "140 litros" | `140 L` (con espacio) |
| "Cochabamba", "La Paz"… | `Zona`: `Santa Cruz` o `Interior` |
| "para mi casa" | `Canal`: `HOGAR` |

Pruebas contra la API real:
```
{Tamaño: "140L", Canal: "Hogar", Zona: "Cochabamba"} → estado "sin_datos"
{Tamaño: "140 L", Canal: "HOGAR", Zona: "Interior"}  → estado "resuelto"
```

**Qué hacer.**
- [ ] Antes de llamar a `precio_producto`, normalizar cada criterio a uno de los valores de `opciones` que devuelve la respuesta `ambiguo`. Cualquier ciudad que no sea Santa Cruz va como `Interior`.
- [ ] Si un valor no se puede mapear con seguridad (por ejemplo XXL), preguntar con un menú cuyas opciones sean exactamente los valores de `opciones`.
- [ ] Ante `estado: "sin_datos"`, no reintentar con variantes al azar: derivar, tal como pide el `message`.

> ⚠️ Además faltan precios cargados en el CRM: el Stretch Film no tiene ninguno y Magia Verde tiene 20 de 50 combinaciones vacías. Mientras Comercial no los cargue, esas consultas **tienen** que derivarse. No es un bug del agente.

---

## P3 — No repetir menús ni la presentación

**Problema.** Hubo 54 casos del mismo mensaje repetido dentro de una misma conversación, repartidos en 46 conversaciones. En la conversación 5107 el agente mandó **4 veces** la misma presentación con su foto mientras el cliente elegía tamaños:
```
20:05:57 Cliente: 140 L (90x110)
20:06:02 Cliente: 35 L (60x63)
20:06:09 Agente:  [foto] Nuestras bolsas Magia Verde están fabricadas con pellets reciclados…
20:06:37 Cliente: 35 L (60x63)
20:06:44 Cliente: 50 L (65x80)
20:06:49 Agente:  [foto] Nuestras bolsas Magia Verde están fabricadas con pellets reciclados…
```

La causa principal es que, por Kommo, **la opción elegida en un menú llega como texto común**: 357 de 1.256 mensajes del cliente eran el título exacto de una opción y llegaron sin `message.selection`. El CRM lo va a corregir (CRM-1).

**Qué hacer.**
- [ ] Usar `message.selection.id` cuando venga. **Depende de CRM-1:** desde ese cambio vendrá también en Kommo.
- [ ] Mientras tanto, si `message.text` coincide con el título de una opción del último menú, tomarlo como esa elección.
- [ ] No reenviar la ficha o la foto de un producto que ya se mandó en esta conversación (está en `history`).
- [ ] No repetir la misma pregunta dentro de un mismo mensaje. Caso real: "¿cuántos paquetes necesita? (1 paquete = 10 bolsas) ¿Podría indicarme cuántos paquetes necesita? (1 paquete = 10 bolsas)".

---

## P4 — Contestar varios mensajes seguidos como uno solo

**Problema.** Hubo 156 ráfagas, en las que el cliente manda 2 o más mensajes en menos de 60 s, y el agente contesta cada uno por separado (106 veces fueron 2 mensajes y 2 respuestas). En la conversación 3686 el cliente escribió "Buenas tardes" y 2 s después preguntó por bolsas al vacío. El agente respondió el saludo con la presentación de Magia Verde y recién al tercer mensaje dijo que no las fabrican.

**Qué hacer.**
- [ ] **Depende de CRM-2:** el CRM va a agrupar los mensajes que lleguen en una ventana corta y mandará **un solo** evento, con todos los textos en `message.text` separados por saltos de línea y la lista en `message.parts`. El agente tiene que contestar al conjunto.
- [ ] Si llega un evento cuyo `message.id` ya no es el último inbound de `history`, descartarlo: ya hay un mensaje más nuevo en camino.

---

## P5 — Manejar mejor los errores

**Problema.** Entre el 07/10 a las 21:08 y el 08/10 a las 08:48 el agente respondió **23 veces** "Disculpe, ocurrió un error. Por favor intente de nuevo en un momento 🙏." Fueron unas 12 horas y nadie se enteró.

**Qué hacer.**
- [ ] Registrar la causa real (excepción, herramienta y status HTTP) de cada error con el `conversation_id`.
- [ ] Ante un error, **derivar** en vez de pedirle al cliente que lo intente de nuevo: llamar al endpoint `handoff` con motivo `error_agente`. Así un asesor lo toma y el CRM lo ve (CRM-3 avisa por correo).
- [ ] No mandar el mismo mensaje de error dos veces en la misma conversación (la 4870 lo recibió dos veces en 44 s).

---

## P6 — Mensajes sin contenido

**Problema.** 28 conversaciones empiezan con "Unable to display this message in CRM. View it in the WhatsApp Business app" y 4 con "Unable to open this message…". Kommo no le pasa al CRM el contenido real (suelen ser mensajes de anuncios o tipos que Kommo no soporta).

**Qué hacer.**
- [ ] Reconocer esos textos y responder con un saludo y el menú de productos, sin tratarlos como una pregunta.

---

## Datos de contacto con el CRM

Sin cambios en los endpoints. Lo nuevo, cuando se publiquen CRM-1 y CRM-2:

```jsonc
"message": {
  "id": 12345,
  "type": "text",
  "text": "Buenas tardes\nQuisiera saber si tienen bolsas para envasar al vacío",
  "parts": [                       // nuevo (CRM-2): los mensajes agrupados, en orden
    {"id": 12344, "text": "Buenas tardes"},
    {"id": 12345, "text": "Quisiera saber si tienen bolsas para envasar al vacío"}
  ],
  "selection": {"id": "CM_00002", "title": "🟢 Bolsas Magia Verde"}  // también en Kommo (CRM-1)
}
```
