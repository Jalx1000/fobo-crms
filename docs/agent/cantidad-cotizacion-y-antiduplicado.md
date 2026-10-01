# Cantidad, cotización pre-elaborada y el fin del mensaje duplicado

**Para:** el equipo del agente (Python · LangChain)
**De:** el lado CRM
**Fecha:** 2026-09-10
**Continúa:** `respuesta-interactivo-handoff-y-tools.md` y `nit-en-cotizaciones.md`

---

## Resumen

| # | Qué | Quién lo arregla |
|---|---|---|
| 1 | El menú se manda dos veces, una con botones y otra sin | **Prompt del agente** (el CRM ya bloquea el segundo) |
| 2 | Falta preguntar cuántas unidades lleva el cliente | **Prompt** + parámetro `cantidad` nuevo |
| 3 | El PASO 5 no deja nada en el CRM | **Nueva tool** `crear_cotizacion` |
| 4 | Ahora llegan webhooks de tipo `reaction` | **Ignorarlos** |

---

## 1. El mensaje duplicado

### Qué pasa

```
23:45:14  interactive  "…¿Qué producto le interesa?"  [lista: 5 opciones]
23:45:16  text         "…¿Qué producto le interesa?   ▸ 🟢 Bolsas  ▸ 🔵 Hules…"
```

El modelo llama a `mostrar_opciones` **y además** escribe la misma pregunta como
texto con las opciones al final. El cliente la recibe dos veces, y la segunda lo
invita a tipear justo lo que los botones ya resolvían. Se repitió en los cuatro
pasos del flujo.

### El CRM ya no lo deja pasar

`POST /conversations/{id}/messages` responde **409** si el último mensaje
saliente es un menú interactivo (de los últimos 120 s) y el texto que llega
**empieza igual**:

```json
{
  "message": "Ya enviaste ese mensaje con botones hace un momento. No lo repitas como texto: …",
  "duplicado_de": 1226
}
```

Tolera diferencias de espaciado y mayúsculas. **No** bloquea una aclaración
distinta después de un menú, ni la misma pregunta más tarde en la charla.

### Qué tienen que hacer ustedes

**Dos cosas, y la segunda es la que importa.**

**a) Tratar el 409 como «ya está hecho», no como error.** No reintenten: el
mensaje ya salió con botones. Que la tool devuelva algo como *«ese mensaje ya se
envió con botones; esperá la respuesta del cliente»* y siga.

**b) Corregir el prompt, que es el fix de verdad.** El 409 evita que el cliente
reciba el duplicado, pero el modelo sigue gastando un turno en escribirlo. Regla
a agregar:

```
Cuando llamás a mostrar_opciones, ESA HERRAMIENTA YA ENVÍA EL MENSAJE.
No repitas la pregunta en tu respuesta de texto ni escribas las opciones como
lista con ▸. Después de llamarla, tu turno termina: esperá la elección.
```

---

## 2. Cantidad y pedido mínimo

`precio_producto` acepta un parámetro nuevo: **`cantidad`** (entero, opcional).

```json
POST /api/v1/productos/catalogo/CM_00002/precio
{ "criterios": {"Tamaño":"50 L","Canal":"HORECA","Zona":"Santa Cruz"}, "cantidad": 25 }
```

```json
{
  "estado": "resuelto",
  "precio": { "precio": "13.0000", "unidad": "por pack de 10 u",
              "minimo": "10 unidades", "cotiza": true,
              "valores": {"Tamaño":"50 L","Canal":"HORECA","Zona":"Santa Cruz"} },
  "cantidad": { "pedida": 25, "minimo": 10, "minimo_texto": "10 unidades",
                "cumple_minimo": true, "total": 325, "message": null }
}
```

Por debajo del mínimo, `cantidad.message` trae qué decir:

```json
"cantidad": { "pedida": 4, "cumple_minimo": false, "total": 52,
  "message": "El pedido mínimo es 10 unidades y el cliente pidió 4. Decíselo y
              preguntale si quiere ajustar la cantidad; no cierres la cotización
              por debajo del mínimo." }
```

**El total lo calcula el CRM.** Hasta ahora la Regla 0 les prohibía calcular
precios pero no les daba forma de dar un total, que es lo primero que pregunta
cualquier cliente. Ese hueco era mío. Ahora: pasen `cantidad` y usen `total`
tal cual — **nunca multipliquen ustedes**.

### ⚠️ El precio de las bolsas es POR PACK

`unidad` dice *«por pack de 10 u»* (5 u en la de 200 L). Un agente que diga
«6 Bs la bolsa de 35 L» está cotizando **diez veces más barato**. Digan siempre
el precio junto con su unidad.

### Cambio para el system prompt (PASO 3)

```
Después de que el cliente elige el producto y la variante, PREGUNTÁ CUÁNTAS
UNIDADES LLEVA. Sin cantidad no hay total y no se puede cerrar nada.

Pasá esa cantidad a precio_producto y decí el precio SIEMPRE con su unidad
("Bs 13,00 por pack de 10 unidades"), nunca el número suelto.

Si cumple_minimo viene en false, decile cuál es el mínimo y preguntale si
quiere ajustar. No sigas al PASO 4 por debajo del mínimo.
```

### Mínimos cargados hoy

| Producto | Mínimo |
|---|---|
| Bolsas Magia Verde | 10 unidades |
| Tapas sin impresión | 1 caja |
| Tapas con impresión | 30 cajas |
| Stretch Film | 50 unidades para envío |

> **Pendiente del negocio:** el documento dice «menos de 10 unidades → derivar al
> supermercado» pero no aclara si son 10 packs o 10 bolsas. Está cargado con la
> frase textual. Si lo confirman, cambia solo el dato.

---

## 3. Cotización pre-elaborada — nueva tool

Hasta ahora el PASO 5 decía *«hemos registrado tu información»* y lo único que
quedaba era la conversación: el asesor tenía que leerla entera para reconstruir
qué quería el cliente.

### `POST /api/v1/productos/conversations/{conversation_id}/cotizacion`

```json
{
  "sku": "CM_00002",
  "criterios": {"Tamaño":"50 L","Canal":"HORECA","Zona":"Santa Cruz"},
  "cantidad": 25,
  "nit": "1234567019",
  "empresa": "Restaurante El Fogón",
  "notas": "Quiere entrega el lunes en la zona norte."
}
```

**201:**

```json
{
  "message": "Cotización pre-elaborada. Un asesor la revisa antes de enviarla.",
  "quote_id": 114,
  "asunto": "Bolsas Magia Verde — 50 L · HORECA · Santa Cruz",
  "cantidad": 25, "precio": 13, "total": 325, "minimo": "10 unidades"
}
```

Crea una cotización real en el CRM con su ítem, cantidad y unitario, vinculada al
contacto y al lead de la conversación, con 7 días de validez. El `nit` viaja
además a la ficha del contacto (ver `nit-en-cotizaciones.md`).

**No manden `precio` ni `total`: no los acepta.** El CRM los resuelve desde la
misma lista que contestó `precio_producto`. Si el agente pudiera mandar el monto,
un error del modelo se volvería una cotización formal con un número que la
empresa nunca autorizó.

### Los 422 y qué hacer con cada uno

| Mensaje | Qué hacer |
|---|---|
| `Faltan datos para cotizar: Canal. …` | Preguntar eso y reintentar |
| La nota de la fila (distribuidor, hogar…) | **No cotizar**: derivar |
| `Esa combinación no tiene precio cargado…` | Derivar |
| `Esta conversación todavía no tiene un contacto asociado…` | Registrar primero el contacto |

El último importa: el CRM se niega a crear una cotización huérfana que nadie va a
encontrar.

### El PASO 5 queda así — CUATRO acciones, en orden

```
1) register_cotizacion  → datos de empresa en el contacto
2) crear_cotizacion     → la cotización con producto, cantidad y precio
3) mandar el texto      → "Hemos registrado tu información…"
4) derivar_a_asesor     → handoff
```

**El orden no es un detalle**: después de `derivar_a_asesor` el CRM rechaza todo
lo que manden (409), así que el texto va ANTES. Y la cotización va antes del
texto para que «hemos registrado tu información» sea cierto cuando se dice.

### Definición de la tool

```python
@tool
async def crear_cotizacion(
    sku: str,
    cantidad: int,
    criterios: dict[str, str] | None = None,
    nit: str | None = None,
    empresa: str | None = None,
    notas: str | None = None,
    *,
    config: RunnableConfig,
) -> str:
    """Deja la cotización pre-elaborada en el CRM al cerrar la toma de interés.

    Llamala en el PASO 5, ANTES del mensaje de despedida y antes de derivar.
    No calcules el precio ni el total: los pone el CRM.

    Args:
        sku: SKU del producto, tal como lo devolvió buscar_productos.
        cantidad: Cuántas unidades lleva el cliente.
        criterios: La variante elegida, igual que en precio_producto.
        nit: NIT del cliente, tal como lo dictó, sin corregirlo.
        empresa: Razón social.
        notas: Lo que dijo el cliente y no entra en ningún campo.
    """
```

`conversation_id` va por `RunnableConfig`, como en el resto — **no** como
argumento del modelo.

---

## 4. Ahora llegan webhooks de tipo `reaction`

El módulo agregó soporte para reacciones (el 👍 que el cliente pone sobre un
mensaje). Eso significa que van a empezar a recibir eventos así:

```json
{ "message": { "type": "reaction", "text": "👍",
               "selection": null, "id": 1230 } }
```

**Ignórenlos.** Un cliente que reacciona con 👍 no está pidiendo nada, y
contestarle rompe el flujo de pasos. Cuando retira la reacción llega igual, con
`text: "Quitó su reacción"`.

Si su filtro de tipos ya descarta lo que no es `text` ni `interactive`, no hay
nada que hacer. Si lo abren para aceptar más tipos, dejen `reaction` afuera.

---

## 5. Cómo probarlo

```bash
TOKEN='...'
BASE='https://imprimir.sofopolis.com/api/v1'

# Precio con cantidad, por encima del mínimo
curl -sS -X POST "$BASE/productos/catalogo/CM_00002/precio" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{"Tamaño":"50 L","Canal":"HORECA","Zona":"Santa Cruz"},"cantidad":25}' | jq

# Por debajo del mínimo: mirá cantidad.message
curl -sS -X POST "$BASE/productos/catalogo/CM_00002/precio" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{"Tamaño":"50 L","Canal":"HORECA","Zona":"Santa Cruz"},"cantidad":4}' | jq

# Cotización (cambiá 123 por un conversation_id con contacto asociado)
curl -sS -X POST "$BASE/productos/conversations/123/cotizacion" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"sku":"CM_00002","criterios":{"Tamaño":"50 L","Canal":"HORECA","Zona":"Santa Cruz"},
       "cantidad":25,"nit":"1234567019","empresa":"Restaurante El Fogón"}' | jq
```

### Escenario end-to-end

1. «hola quiero bolsas» → menú de producto — **debe llegar UNO SOLO**
2. ciudad → uso → tamaño
3. **«¿cuántas unidades lleva?»** ← lo nuevo
4. «25» → *«Bs 13,00 por pack de 10 unidades. Total: Bs 325»*
5. probar «4» → debe decir el mínimo y no avanzar
6. datos de empresa + NIT
7. PASO 5 → **verificar en el CRM** que la cotización existe con el producto, la
   cantidad y el total; que el NIT está en la ficha del contacto; y que la
   conversación quedó derivada
8. escribirle otra vez al bot → **no debe contestar**

---

## 6. Qué necesito de vuelta

- El log de un flujo completo hasta la cotización.
- Confirmación de que el 409 se trata como «ya enviado» y no genera reintento.
- Si el modelo sigue escribiendo el menú como texto después del cambio de prompt,
  el turno exacto: se corrige en el prompt, no en la API.
