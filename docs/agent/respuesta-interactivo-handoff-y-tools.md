# Respuesta: mensajes interactivos, handoff en el PASO 5 y tools bindeadas

**Para:** el equipo del agente (Python · LangChain)
**De:** el lado CRM
**Fecha:** 2026-09-09
**Responde a:** los 3 puntos de decisión tras instalar `precio_producto` y el prompt nuevo

---

## Resumen

| # | Punto | Decisión |
|---|---|---|
| 1 🔴 | Los toques de botón se descartan | **Aplicá el fix ahora.** Rutear por `selection.id`, nunca por el título |
| 2 🟡 | El PASO 4 no persiste nada | **PASO 5 = persistir → texto → handoff**, en ese orden exacto |
| 3 🟡 | 7 tools viejas bindeadas | **Dejá 7 en total**: las 5 del protocolo + `register_cotizacion` + `derivar_a_asesor` |
| — | `ficha_producto` sin la lista cruda | **Tenías razón.** Que quede afuera |

Todo lo de abajo está verificado contra el código del CRM, no de memoria.

---

## 1. 🔴 Mensajes interactivos — aplicalo ahora

Es bloqueante y tenés razón en el diagnóstico. Sin esto el protocolo cuelga en el
PASO 1.

### Qué manda el CRM cuando el cliente toca un botón

```json
{
  "event": "message.received",
  "conversation_id": 1234,
  "message": {
    "id": 987,
    "type": "interactive",
    "text": "🟢 Bolsas Magia Verde",
    "selection": { "id": "prod_bolsas", "title": "🟢 Bolsas Magia Verde" },
    "timestamp": "2026-09-09T17:40:12-04:00"
  }
}
```

`message.text` **ya viene con el título** del botón: `extractBody()` del gateway
devuelve `interactiveReply($message)['title']`, así que el historial y el inbox
se leen bien sin tratamiento especial.

`message.selection` sale del payload crudo que guardó el webhook —el mismo dato
que mandó Meta, sin una copia que pueda quedar desincronizada— y existe tanto
para botones (`button_reply`) como para listas (`list_reply`).

### La regla

> **Ruteá por `selection.id`. Nunca por el texto.**

El título es *copy*: cambia en cuanto alguien edite el prompt, y el emoji lo hace
frágil de comparar. El `id` lo ponés vos al llamar a `mostrar_opciones`, así que
controlás las dos puntas — es el único contrato estable entre el menú que
mostraste y la respuesta que recibís.

### Follow-up aparte (no lo metas en este fix)

El mismo filtro deja mudo al agente si el cliente manda un **audio, una foto o
una ubicación**. Descartar en silencio es lo peligroso: el cliente ve que el bot
lo ignora. Que conteste *"no puedo escuchar audios, ¿me lo escribís?"* ya es
infinitamente mejor. Es otro ticket, pero anotalo.

---

## 2. 🟡 El PASO 5 tiene que llamar a handoff

**No es opcional.** No es una preferencia de diseño: es el mecanismo que hace
verdadera la frase que el agente le dice al cliente.

### Por qué

`POST /api/v1/whatsapp/conversations/{id}/handoff` pone `aiEffective()` en
`false`. A partir de ese momento **todos** los envíos del agente devuelven
**409**: `send`, `sendInteractive` y `media`.

Sin esa llamada pasan dos cosas, las dos malas:

1. El agente promete *"un asesor se comunicará contigo"* y **sigue contestando**
   como si nada.
2. Cuando el asesor entra a cerrar la venta, **la IA le habla por encima** al
   cliente en la misma conversación.

El 409 no es una restricción molesta que haya que sortear. Es la garantía.

### El orden — acá está la trampa

```
1) register_cotizacion        ← persistir los datos de empresa
2) mandar el texto del PASO 5 ← la confirmación al cliente
3) derivar_a_asesor           ← handoff
```

**Si llamás a handoff antes de mandar el texto, el mensaje de confirmación se
come el 409** y el cliente se queda sin respuesta final. Es el bug más fácil de
introducir en esta parte.

Después del paso 3, el agente no vuelve a escribir en esa conversación aunque el
cliente siga escribiendo. La atiende un humano.

### Sobre persistir los datos

*"Hemos registrado tu información"* tiene que ser cierto. La API REST de Krayin
está disponible con el mismo token de sanctum:

```
POST /api/v1/contacts/persons     ← razón social, correo, teléfono
POST /api/v1/leads                ← la oportunidad
PUT  /api/v1/leads/attributes/edit/{id}
```

> ⚠️ **Si `register_cotizacion` hoy no persiste de verdad** —solo loguea, o
> escribe en un lado que nadie lee— **decímelo antes de aplicar esto.** Prefiero
> armar el endpoint del lado del CRM a que el PASO 5 prometa un registro que no
> existe. Es exactamente el tipo de promesa que reaparece como un cliente enojado
> porque nadie lo llamó.

---

## 3. 🟡 Desbindeá, pero no las dos que necesita el punto 2

**Quedan 7:**

| Tool | Para qué |
|---|---|
| `buscar_productos` | ver qué hay y con qué ejes se cotiza |
| `ficha_producto` | describir con datos reales |
| `precio_producto` | **el único origen de un precio** |
| `enviar_material` | fotos y documentos |
| `mostrar_opciones` | los menús de los PASOS 1, 2 y 3 |
| `register_cotizacion` | PASO 5, acción 1 |
| `derivar_a_asesor` | PASO 5, acción 3 |

**Fuera las otras 5** (`mover_lead_por_ciudad`, `crear_quote`, etc.).

La regla que aplico:

> Una tool bindeada que el prompt no menciona es una tool que el modelo va a
> encontrar una excusa para llamar.

Y el corolario: si decidís dejar alguna, **tiene que estar en el prompt**. No hay
término medio entre "está disponible" y "está documentada".

`mover_lead_por_ciudad` y `crear_quote` son de otro flujo. Si después hacen
falta, vuelven con su párrafo en el prompt.

---

## 4. El detalle de `ficha_producto`: tenías razón

Mi diseño original era optimista. Metí `precios` en la ficha pensando en que el
modelo pudiera comparar (*"la de 50 L sale un poco más"*).

Pero con los precios de bolsas todavía sin cargar, eso le vuelca al modelo
**25 filas con `cotiza: false`**: puro ruido que además lo tienta a cotizar desde
ahí, contra la Regla 0.

**Dejalo afuera.** Se revisa cuando se cumplan las dos condiciones:

1. Los 15 precios de Magia Verde estén cargados en el CRM.
2. Veamos en conversaciones reales que los clientes preguntan comparando.

---

## 5. Nota sobre `_precio_desc()`

Bien visto. Fue una omisión mía: en el documento dije que `buscar_productos` y
`ficha_producto` "siguen igual", cuando en realidad necesitaban el ajuste para no
imprimir `Bs None` con la API nueva. Lo corregí en la versión escrita del
documento, pero tu copia era anterior.

---

## 6. Cómo verificar el flujo completo

Además de los 6 escenarios que ya validaste, agregá este:

**Escenario 7 — flujo entero hasta la derivación**

1. «hola quiero bolsas» → menú de producto (tocar el botón, no escribir)
2. → menú de ciudad → menú de uso → tamaño
3. → el agente deriva (los precios de bolsas no están cargados: correcto)
4. → PASO 4: dar razón social, NIT, correo, dirección
5. → PASO 5

**Verificar en el CRM:**

- [ ] Los datos de empresa quedaron guardados donde alguien los pueda leer.
- [ ] El cliente recibió el texto de confirmación (no se comió un 409).
- [ ] La conversación quedó **derivada** (handoff abierto, IA apagada).
- [ ] Escribirle algo más al bot: **NO debe contestar.**

El último punto es la única forma real de comprobar que el handoff quedó bien.
