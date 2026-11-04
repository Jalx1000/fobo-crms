# TODO - Agente de IA de Imprimir (auditoría del 12/10/2026)

> **Para:** equipo que mantiene el agente (Python · LangChain / LangGraph).
> **Base:** 303 conversaciones reales y ~2.400 mensajes del 02/10 al 12/10/2026 (301 por Kommo y 2 por Messenger).
> **Lo que anda bien y no hay que romper:** el agente responde en 12 s de mediana y 14 s en el p90, con mensajes cortos (p50 de 122 caracteres) y buen uso de menús.

Los cambios del lado del CRM van en otro documento (`auditoria-2026-10-12-todo-crm.md`). Algunos puntos de abajo **dependen** de esos cambios, y está marcado en cada uno.

---

## P0 - El agente NO menciona precios (decisión del negocio, 12/10)

**Regla.** El agente **nunca** dice un precio, un total, un monto en Bs ni un rango ("desde X Bs"), aunque el cliente insista. El precio lo da **la cotización**, que prepara y envía el asesor.

**Qué hacer.**
- [ ] Quitar del system prompt la REGLA 1 y todo lo que indique decir precios (`precio_producto` → "decí el precio con su unidad", "usá el `total`").
- [ ] `precio_producto` puede seguir usándose **solo internamente**, para saber qué datos faltan (`faltan` / `opciones`). Nunca para mostrarle al cliente ni `precio` ni `total`.
- [ ] Ante "¿cuánto cuesta?" o "¿precio?" no se dice nada sobre el precio ni sobre la cotización: el agente sigue con la siguiente pregunta que falta (solo los datos que faltan).
- [ ] Prueba: en 20 conversaciones de prueba que preguntan precio, ninguna respuesta contiene "Bs", un número seguido de moneda ni la palabra "precio" acompañada de un monto.

---

## P1 - Toda promesa de cotización termina en `crear_cotizacion`

**Problema.** En 50 conversaciones el agente dijo "Con estos datos preparo su cotización y en breve se la hacemos llegar", y en **33 no se creó ninguna cotización**. En muchas ni siquiera se llamó a `crear_cotizacion`. El cliente queda esperando algo que no existe.

Conversaciones sin cotización: 3689, 3700, 3707, 4253, 4261, 4368, 4371, 4422, 4428, 4430, 4858, 4864, 4865, 4883, 4988, 4995, 4996, 5020, 5029, 5030, 5035, 5040, 5049, 5062, 5079, 5099, 5103, 5104, 5108, 5122, 5128, 5134, 5135.

**Qué hacer.**
- [ ] Con producto, variante, cantidad, ciudad y nombre o NIT, llamar **siempre** a `register_cotizacion` y después a `crear_cotizacion`, **antes** de decirle al cliente que se prepara la cotización.
- [ ] Decir "preparo su cotización" **solo** si `crear_cotizacion` devolvió 201. Si devolvió 422, leer `message`:
  - "Faltan datos para cotizar: …" → preguntar esos datos y volver a llamar.
  - cualquier otro motivo → derivar con `handoff` (motivo `cotizacion_rechazada`) y decir "Un asesor le envía la cotización en breve".
- [ ] Si el cliente pide **dos** cotizaciones (por ejemplo, la 5069: "una de 12 y otra de 50"), llamar a `crear_cotizacion` una vez por cada una.
- [ ] Desde CRM-6, el CRM **ya no rechaza** por falta de precio: crea la cotización "con precio pendiente" para que la complete el asesor. Los únicos 422 que quedan son por datos faltantes.

**Aceptación.** Cada conversación con el texto "preparo su cotización" tiene una cotización creada en el CRM.

> **No hace falta mover el pedido de etapa.** Cuando `crear_cotizacion` devuelve 201, el CRM pasa el pedido de NO ATENIDOS a SOLICITUD RECIBIDA por su cuenta (desde el 12/10). El agente no tiene que hacer nada más.

---

## P2 - Traducir lo que dice el cliente a los valores del catálogo

Hace falta para que `crear_cotizacion` reciba criterios válidos:

| El cliente o el anuncio dice | El catálogo espera |
|---|---|
| "XXL" | `Tamaño`: `35 L`, `50 L`, `75 L`, `140 L`, `200 L` (XXL no existe; Comercial define a cuál corresponde) |
| "140L", "140 litros" | `140 L` (con espacio) |
| "Cochabamba", "La Paz"… | `Zona`: `Santa Cruz` o `Interior` |
| "para mi casa" | `Canal`: `HOGAR` |

- [ ] Normalizar cada criterio a uno de los valores de `opciones`. Cualquier ciudad que no sea Santa Cruz va como `Interior`.
- [ ] Si un valor no se puede mapear con seguridad, preguntarlo con un menú cuyas opciones sean exactamente esos valores.

---

## P1b - Responder primero lo que pregunta el anuncio (sin precios)

El 79 % de las conversaciones empieza con el texto de un anuncio ("¿Cuáles son los tamaños disponibles del Stretch Film?" 102, "¿Cuánto cuesta la bolsa XXL?" 81, "¿Cuánto cuesta la bolsa de 140L?" 54), y 101 de 303 terminan sin un segundo mensaje.

- [ ] Si preguntan por tamaños o presentaciones, la primera respuesta los nombra (Stretch Film: Manual 4,7 kg y Automático 15,7 kg).
- [ ] Si preguntan precio, aplica P0: sin mencionar el precio ni la cotización, seguir con la primera pregunta que falta.
- [ ] La presentación de la marca va como máximo una vez por conversación y nunca reemplaza a la respuesta.

---

## P3 - No repetir menús ni la presentación

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

## P4 - Contestar varios mensajes seguidos como uno solo

**Problema.** Hubo 156 ráfagas, en las que el cliente manda 2 o más mensajes en menos de 60 s, y el agente contesta cada uno por separado (106 veces fueron 2 mensajes y 2 respuestas). En la conversación 3686 el cliente escribió "Buenas tardes" y 2 s después preguntó por bolsas al vacío. El agente respondió el saludo con la presentación de Magia Verde y recién al tercer mensaje dijo que no las fabrican.

**Qué hacer.**
- [ ] **Depende de CRM-2:** el CRM va a agrupar los mensajes que lleguen en una ventana corta y mandará **un solo** evento, con todos los textos en `message.text` separados por saltos de línea y la lista en `message.parts`. El agente tiene que contestar al conjunto.
- [ ] Si llega un evento cuyo `message.id` ya no es el último inbound de `history`, descartarlo: ya hay un mensaje más nuevo en camino.

---

## P5 - Manejar mejor los errores

**Problema.** Entre el 07/10 a las 21:08 y el 08/10 a las 08:48 el agente respondió **23 veces** "Disculpe, ocurrió un error. Por favor intente de nuevo en un momento 🙏." Fueron unas 12 horas y nadie se enteró.

**Qué hacer.**
- [ ] Registrar la causa real (excepción, herramienta y status HTTP) de cada error con el `conversation_id`.
- [ ] Ante un error, **derivar** en vez de pedirle al cliente que lo intente de nuevo: llamar al endpoint `handoff` con motivo `error_agente`. Así un asesor lo toma, y el CRM avisa por correo (CRM-3).
- [ ] No mandar el mismo mensaje de error dos veces en la misma conversación (la 4870 lo recibió dos veces en 44 s).

---

## P6 - Mensajes sin contenido

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
