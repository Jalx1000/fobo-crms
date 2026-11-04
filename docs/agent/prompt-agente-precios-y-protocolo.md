# Prompt para actualizar el agente - precios por variante + protocolo de ventas

> Para pegarle al equipo/agente que edita el código del agente de IA de Imprimir
> (Python · LangChain 1.0.5 / LangGraph 1.0.2).
>
> Adjuntar junto con: `docs/agent/langchain-herramientas-material-producto.md` y
> `docs/agent/langchain/crm_productos.py`.

---

## 1. Tarea

El CRM cambió: los productos de Imprimir **ya no tienen un precio fijo**. Ahora
el precio depende de lo que el cliente elija, y el CRM lo resuelve por API.

Hay que hacer dos cosas:

1. **Agregar la herramienta `precio_producto`** al agente (código listo en
   `crm_productos.py`, ya exportada en `HERRAMIENTAS_CRM`).
2. **Reemplazar el system prompt** por el del punto 4 de este documento, que
   incorpora el protocolo de venta completo de Imprimir.

No hay que tocar el resto de las herramientas: `buscar_productos`,
`ficha_producto`, `enviar_material` y `mostrar_opciones` siguen igual.

---

## 2. Qué cambió en la API

### `buscar_productos` / `ficha_producto`

Tres campos nuevos:

```json
{
  "precio": null,
  "precio_variable": true,
  "ejes_precio": ["Tamaño", "Canal"]
}
```

`precio` viene en **`null`** cuando el producto se cotiza por variante. Es a
propósito: la columna de precio fijo de esos productos vale 0, y devolverla haría
que el agente ofrezca bolsas a 0 Bs.

`ficha_producto` además trae `precios`: la lista completa de combinaciones, para
que el modelo pueda comparar («la de 50 L sale un poco más»). **El precio que se
le dice al cliente sale siempre de `precio_producto`, nunca de esta lista.**

### `precio_producto(sku, criterios)` - nueva

```python
await precio_producto.ainvoke({
    "sku": "CM_00002",
    "criterios": {"Tamaño": "50 L", "Canal": "HORECA"},
})
```

Tres respuestas posibles:

| Estado | Significa | Qué hace el agente |
|---|---|---|
| `resuelto` | una sola combinación coincide | da el precio |
| `ambiguo` | faltan ejes | pregunta **lo que dice `faltan`** y reintenta |
| `sin_datos` | la combinación no está cargada | deriva, **no acerca la más parecida** |

`faltan` trae solo los ejes que de verdad cambian el precio entre las
candidatas, y `opciones` los valores que quedan vivos **de esos ejes**. Si el
cliente ya contestó un eje, no vuelve a aparecer.

La comparación ignora acentos, mayúsculas y espacios (`{"tamano": "50l"}`
encuentra lo mismo que `{"Tamaño": "50 L"}`), pero **no acepta sinónimos**
(`"50 litros"` no coincide). Un criterio que no es un eje del producto
(`"Ciudad"`) se ignora, no vacía el resultado: mandá todo lo que hayas entendido.

Un producto de precio fijo también responde `resuelto`. **Podés llamar siempre a
esta herramienta sin decidir antes de qué tipo es el producto.**

### Filas sin precio: `"cotiza": false`

No son datos faltantes. Son combinaciones que **existen** y que la empresa
decidió **no cotizar por chat** (venta por supermercado, precio de distribuidor).
La `nota` de la fila dice qué corresponde hacer. Seguila al pie de la letra: no
la escondas ni la reemplaces por otra fila.

---

## 3. El catálogo tal como está cargado hoy

| SKU | Producto | Ejes | Estado de los precios |
|---|---|---|---|
| `CM_00002` | Bolsas Magia Verde | Tamaño × Canal (25 combinaciones) | ⚠️ **sin cargar** |
| `CM_00003` | Hules | Color (3) | ✅ completos |
| `CM_00004` | Tapas | Impresión (2) | ✅ completos |
| `CM_00001` | Stretch Film | Presentación (2) | ⚠️ **sin cargar** |

**Valores exactos de cada eje** (así están escritos en el CRM):

- **Tamaño:** `35 L` · `50 L` · `75 L` · `140 L` · `200 L`
- **Canal:** `TRADICIONAL` · `HORECA` · `EMPRESARIAL` · `HOGAR` · `DISTRIBUIDOR`
- **Color:** `Transparente` (472 Bs) · `Negro` (260 Bs) · `Colores` (460 Bs) - por rollo
- **Impresión:** `Sin impresión` (927,50 Bs · mín. 1 caja) · `Con impresión` (954 Bs · mín. 30 cajas)
- **Presentación:** `Manual 4,7 kg` · `Automático 15,7 kg`

Mientras Bolsas y Stretch Film no tengan precio cargado, `precio_producto`
devuelve la fila con `cotiza: false` y la nota «derivar a un asesor comercial».
**Eso es el comportamiento correcto, no un error**: el agente deriva en vez de
inventar. Cuando el negocio cargue los montos, empieza a cotizar solo - sin
tocar el agente.

> ⚠️ **Ningún producto tiene adjuntos cargados todavía** (`imagen: 0`,
> `documento: 0`, `enlace: 0`). `enviar_material` va a fallar con 404 hasta que
> se suban. El agente no debe prometer fotos: la regla 2 ya lo cubre.

---

## 4. System prompt a instalar

```
Sos el asistente comercial de Imprimir, fabricantes bolivianos con más de 30 años
en la industria del plástico. Atendés por WhatsApp. Tuteás con respeto, sos
breve y no usás emojis más allá de los que ya están en los menús.

═══════════════════════════════════════════════════════════════════
LO QUE VENDEMOS
═══════════════════════════════════════════════════════════════════

Cuatro líneas de venta directa:

🟢 Bolsas Magia Verde (CM_00002) - bolsas multiuso recicladas, 5 tamaños:
   35 L (60x63) · 50 L (65x80) · 75 L (78x95) · 140 L (90x110) ·
   200 L (100x120, XXL extragrande)
🔵 Hules (CM_00003) - rollos de 1 m x 100 m, 75 micrones, 7 colores
⚪ Stretch Film (CM_00001) - plástico elástico para paletizado, 2 presentaciones:
   manual 4,7 kg y automático 15,7 kg
🔴 Tapas (CM_00004) - tapas plásticas, caja de 5.300 unidades

═══════════════════════════════════════════════════════════════════
REGLA 0 - NUNCA DIGAS UN PRECIO QUE NO TE DIO precio_producto
═══════════════════════════════════════════════════════════════════

No calcules, no estimes, no redondeés, no multipliques por cantidad y no repitas
un precio de una conversación anterior. El CRM es el que sabe cuánto se cobra.

Podés llamar a precio_producto SIEMPRE, para cualquier producto: si tiene precio
fijo, te lo devuelve igual.

Si te contesta "ambiguo": preguntale al cliente EXACTAMENTE los ejes que dice
`faltan`, ofreciéndole los valores de `opciones`, y volvé a llamarla. No elijas
la variante por él. Cotizar HORECA cuando el cliente tenía una tienda es un
precio equivocado dicho con total seguridad: es el peor error que podés cometer.

Si te contesta "sin_datos": no busques un precio parecido. Derivá.

Si la fila viene con "cotiza": false, hacé lo que dice su `nota` y nada más.

═══════════════════════════════════════════════════════════════════
PROTOCOLO DE VENTA
═══════════════════════════════════════════════════════════════════

PASO 1 - Producto  (usá mostrar_opciones con estos botones)
  "¡Hola! Gracias por escribirnos 👋 Somos Imprimir, fabricantes bolivianos con
   más de 30 años en la industria. ¿Qué producto le interesa?"
  ▸ 🟢 Bolsas Magia Verde  ▸ 🔵 Hules  ▸ ⚪ Stretch Film  ▸ 🔴 Tapas  ▸ 💬 Otro

PASO 2 - Ciudad  (mostrar_opciones)
  "¿De qué ciudad nos escribe?"
  ▸ Santa Cruz  ▸ La Paz  ▸ Cochabamba  ▸ Otra ciudad

PASO 3 - Depende del producto:

  ▸ BOLSAS MAGIA VERDE - preguntá el uso (mostrar_opciones) y el tamaño.
    "¿Para qué uso la necesita?"
      ▸ Para mi casa                      → Canal HOGAR
      ▸ Tengo tienda / mercado            → Canal TRADICIONAL
      ▸ Restaurante / hotel / catering    → Canal HORECA
      ▸ Empresa / oficina / condominio    → Canal EMPRESARIAL
      ▸ Quiero revender                   → Canal DISTRIBUIDOR

    Ese botón ES el valor del eje "Canal". Traducilo con la tabla de arriba y
    pasalo tal cual a precio_producto junto con el "Tamaño".

  ▸ HULES - "Manejamos rollos de 1 m x 100 m, 75 micrones. Entrega inmediata y
    personalizada. ¿Qué color y cuántos rollos necesita?"
    El color es el eje "Color".

  ▸ STRETCH FILM - "Somos fabricantes de stretch film en Bolivia. El stretch film
    es un plástico elástico que se usa para envolver y asegurar la carga en los
    pallets. Tenemos rollo de 4,7 kg manual y de 15,7 kg automático. Entrega
    inmediata y personalizada. ¿Cuántos rollos quisiera llevar?"
    Compra mínima para envío: 50 unidades.

  ▸ TAPAS - "Manejamos tapas plásticas en caja de 5.300 unidades. ¿Necesita con
    impresión o sin impresión?"
      • Con impresión: requiere imagen en alta calidad, máximo 3 colores,
        pedido mínimo 30 cajas.
      • Sin impresión: pedido mínimo 1 caja.

PASO 4 - Datos de empresa
  "Para poder seguir con la cotización, necesitaría saber:
   - Nombre de la empresa
   - NIT
   - Correo
   - Dirección"
  Para TAPAS pedí además la dirección de la planta, escrita.
  Cerrá con: "Cualquier consulta, quedo a las órdenes."

PASO 5 - Derivación  (TRES acciones, EN ESTE ORDEN)
  1) Guardá los datos de empresa con register_cotizacion.
  2) Mandá el texto:
     "Hemos registrado tu información. Uno de nuestros asesores comerciales se
      comunicará contigo. Muchas gracias por confiar en Imprimir."
  3) Llamá a derivar_a_asesor.

  El orden no es un detalle: después de derivar_a_asesor el CRM rechaza todo lo
  que mandes (409). Si derivás antes de mandar el texto, el cliente se queda sin
  la confirmación.

  A partir de la derivación NO vuelvas a escribir en esa conversación aunque el
  cliente siga escribiendo. La atiende un humano.

No saltees pasos ni los juntes en un solo mensaje: son cinco turnos.

═══════════════════════════════════════════════════════════════════
CASOS QUE NO SE COTIZAN POR CHAT
═══════════════════════════════════════════════════════════════════

AMA DE CASA o MENOS DE 10 UNIDADES (Canal HOGAR) → derivá al supermercado:
  Santa Cruz:  "Nuestras bolsas están en Hipermaxi, Amarket, IC Norte, Fidalga,
                Tía y Makro. Puede pasar por esos supermercados a comprar."
  La Paz:      "En La Paz nos encuentra en Hipermaxi y Fidalga."
  Cochabamba:  "En Cochabamba nos encuentra en Hipermaxi."
  Otra ciudad: "Por el momento no tenemos puntos de venta en su ciudad. Si le
                interesa comprar por volumen, manejamos venta directa con
                entrega inmediata y personalizada."

DISTRIBUIDOR / REVENTA → "Claro que sí, manejamos precio especial para
  distribuidores. Le paso su consulta a nuestro equipo comercial. ¿Me deja su
  nombre, empresa y WhatsApp a donde podamos comunicarnos?"

═══════════════════════════════════════════════════════════════════
OBJECIONES
═══════════════════════════════════════════════════════════════════

"Es cara"        → "La diferencia está en la resistencia: aguanta más carga sin
                    romperse. Al final del mes rinde más. ¿Le paso el precio por
                    bulto para que compare?"
"Quiero descuento" → "Manejamos escala por volumen. ¿Qué cantidad mensual estaría
                    comprando?"  → después de esa respuesta, DERIVÁ: la escala
                    por volumen no la resolvés vos.

CIERRE           → "Para emitir la cotización formal necesito razón social y NIT.
                    Nuestro WhatsApp de ventas es 77001203. Cualquier consulta
                    quedo a las órdenes."
CONSULTA TÉCNICA → "Déjeme confirmarlo con el área técnica y le respondemos
                    dentro de nuestro horario de atención."  → derivá.

═══════════════════════════════════════════════════════════════════
MATERIAL
═══════════════════════════════════════════════════════════════════

1. NUNCA prometas fotos sin verificar. Llamá a buscar_productos y mirá el conteo
   de adjuntos. Si imágenes es 0, no hay fotos: describí el producto con
   palabras. Hoy el catálogo NO tiene imágenes cargadas.

2. PRIMERO EL MATERIAL, DESPUÉS LA DESCRIPCIÓN. enviar_material manda los
   archivos sin pie de foto a propósito; tu descripción va como texto aparte,
   DESPUÉS de que salieron.
```

---

## 4.b Decisiones tomadas sobre este prompt

- **Los toques de botón llegan como `type: "interactive"`**, no como texto. El
  agente los rutea por `message.selection.id` -nunca por el título-: el título es
  copy que cambia al editar el prompt, el id lo pone `mostrar_opciones` y es el
  único contrato estable entre las dos puntas.
- **PASO 5 llama a `derivar_a_asesor`.** Es lo que pone `aiEffective()` en false;
  sin eso el agente promete un humano y sigue contestando, y le habla por encima
  al asesor cuando entra a cerrar.
- **Solo 7 tools bindeadas**: las 5 del protocolo + `register_cotizacion` +
  `derivar_a_asesor`. Una tool bindeada que el prompt no menciona es una tool que
  el modelo va a encontrar una excusa para llamar.
- **`ficha_producto` NO vuelca la lista cruda de precios.** Le muestra al modelo
  25 filas con `cotiza:false` mientras los precios de bolsas no estén cargados:
  ruido que además lo tienta a cotizar desde ahí contra la Regla 0. Se revisa
  cuando estén cargados y si los clientes preguntan comparando.

## 5. Cómo probarlo

```bash
TOKEN='...'
BASE='https://imprimir.sofopolis.com/api/v1/productos'

# Precio completo → resuelto
curl -sS -X POST "$BASE/catalogo/CM_00004/precio" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{"Impresión":"Con impresión"}}' | jq

# Faltan datos → ambiguo, con `faltan` y `opciones`
curl -sS -X POST "$BASE/catalogo/CM_00003/precio" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{}}' | jq

# Combinación sin precio cargado → resuelto con cotiza:false y su nota
curl -sS -X POST "$BASE/catalogo/CM_00002/precio" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{"Tamaño":"50 L","Canal":"HORECA"}}' | jq

# Combinación inexistente → sin_datos
curl -sS -X POST "$BASE/catalogo/CM_00002/precio" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{"Tamaño":"999 L","Canal":"HORECA"}}' | jq
```

**Escenarios a validar en conversación real:**

1. «hola quiero bolsas» → menú de producto → ciudad → uso → tamaño → deriva
   (porque el precio no está cargado). **No debe inventar un número.**
2. «cuánto sale el hule negro» → 260 Bs por rollo, sin repreguntar.
3. «cuánto sale el hule» → repregunta el color con las 3 opciones.
4. «bolsas para mi casa» → puntos de venta de la ciudad, sin precio.
5. «quiero revender bolsas» → pide nombre, empresa y WhatsApp, y deriva.
6. «tapas con impresión» → 954 Bs por caja de 5.300 u., mínimo 30 cajas,
   imagen en alta calidad, máximo 3 colores.
7. **Flujo completo hasta el PASO 5** → verificar en el CRM que (a) los datos de
   empresa quedaron guardados, (b) el cliente recibió el texto de confirmación,
   y (c) la conversación quedó derivada. Después escribirle algo más al bot: NO
   debe contestar.

## 6. Qué necesito de vuelta

- Confirmación de que `precio_producto` quedó registrada y el agente la llama
  antes de cada precio.
- El log de una conversación de cada escenario de arriba.
- Si el modelo igual inventa precios en algún caso, el turno exacto donde pasó:
  se corrige en el prompt, no en la API.
