# Instrucciones para el agente de IA — productos y ubicación del cliente

Qué cambió en el CRM y cómo tiene que usarlo el agente. Todo lo que está acá está
verificado contra la instalación de Sensia por HTTP real, no deducido de la documentación.

Base URL: `https://sensia.sofopolis.com`
Autenticación: `Authorization: Bearer <token>` (Sanctum). El token actual se llama
`android`.

---

## 1. Los productos ahora traen sus atributos personalizados

`GET /api/v1/products` devolvía 8 campos y ningún atributo custom. Ahora devuelve 17.

### Lo que se agregó

| Campo | Tipo | Para qué sirve |
|---|---|---|
| `ciudad_producto_sucursal` | array de ids | **De qué depósito es el producto** |
| `ciudad_producto_sucursal_labels` | array de textos | Los nombres de esos depósitos |
| `product_type` | entero | Id del tipo de producto |
| `product_type_label` | texto | `Productos Sensia` o `Promociones Combos` |
| `products` | booleano | **Estado: `true` activo, `false` inactivo** |
| `precio_promocion` | texto o `null` | Precio con descuento, si tiene |
| `combos_productos_cantidad` | texto con JSON | Qué incluye el combo |
| `tiempo_anejo_vino` | texto o `null` | No se usa en Sensia |
| `product_image` | texto o `null` | Imagen |

Aplica a los tres endpoints: `GET /api/v1/products`, `GET /api/v1/products/{id}` y
`GET /api/v1/products/search`.

### Las dos reglas que el agente DEBE aplicar

**Regla 1 — Nunca ofrecer un producto con `products: false`.**

Está inactivo. Hoy la Bomba Eléctrica (id 35) está en `false`: si el agente la ofrece,
ofrece algo que el negocio dio de baja.

**Regla 2 — Elegir el producto por el depósito del cliente, no por el nombre.**

Hay dos productos con el **mismo nombre** y distinto precio, y lo único que los distingue
es la zona:

```json
{ "id": 37, "name": "Botellón de agua purificada 20 L", "price": "7500.0000",
  "ciudad_producto_sucursal": [1, 9],
  "ciudad_producto_sucursal_labels": ["Barrio Alto Comedero", "Palpalá"] }

{ "id": 32, "name": "Botellón de agua purificada 20 L", "price": "8000.0000",
  "ciudad_producto_sucursal": [8],
  "ciudad_producto_sucursal_labels": ["San Salvador de Jujuy"] }
```

Si el cliente es de San Salvador de Jujuy, el precio es **$8.000** (id 32). Si es de
Palpalá o Barrio Alto Comedero, **$7.500** (id 37). Elegir por nombre da el precio
equivocado la mitad de las veces.

El depósito `10` se llama **"Todas"** y significa que el producto se vende en cualquier
zona.

### Detalles de formato

- `price` llega como texto con 4 decimales: `"7500.0000"`.
- `combos_productos_cantidad` llega como **texto que contiene JSON**, así que hay que
  decodificarlo dos veces:
  ```json
  "[{\"id\":32,\"name\":\"Botellón de agua purificada 20 L\",\"qty\":2}]"
  ```
- Los ids y las etiquetas vienen en claves separadas a propósito: los ids sirven para
  filtrar, las etiquetas para hablarle al cliente.

---

## 2. Cómo guardar la ubicación del cliente

Hay dos caminos y conviene usar los dos, porque se complementan.

### Camino A — Pedirle el pin por WhatsApp (el preferido)

**El CRM guarda la ubicación que el cliente manda por WhatsApp y ahora se la pasa al
agente en el evento.** Alcanza con pedírsela:

> "¿Me compartís tu ubicación? Tocá el clip 📎 → Ubicación → Enviar tu ubicación actual."

El evento `message.received` de un pin llega así. Las coordenadas van en **tres lugares a
la vez** a propósito, para que el agente lea la que le resulte más cómoda:

```jsonc
"message": {
  "id": 3240,
  "type": "location",
  // Listo para copiar tal cual a `ubicacion_lead` (6 decimales, ~11 cm).
  "text": "-24.255002,-65.208238",
  // Objeto anidado.
  "location": {
    "lat": -24.2550022,
    "lng": -65.2082377,
    "name": "Casa",            // puede ser null
    "address": "Belgrano 450", // puede ser null
    "url": "https://www.google.com/maps?q=-24.2550022,-65.2082377"
  },
  // Y los mismos números planos.
  "latitude": -24.2550022,
  "longitude": -65.2082377,
  "timestamp": "2026-10-06T16:34:38-04:00"
}
```

Cuando el pin trae nombre o dirección del lugar, `text` ya viene en el formato completo de
`ubicacion_lead`: `"-24.188478,-65.300333 | Casa, Belgrano 450"`.

`location` es `null` y no hay `latitude`/`longitude` sólo si el mensaje llegó sin
coordenadas utilizables. En ese caso —y nada más en ese caso— hay que pedirle la dirección
por texto y usar el Camino B.

**Esto antes no funcionaba.** Hasta el 06/10/2026 el evento salía con `type: "location"`,
`text: null` y sin coordenadas en ningún campo: el pin del cliente quedaba guardado en la
base del CRM pero nunca salía de ahí, así que el agente no tenía nada que guardar. Las
ubicaciones recibidas antes de esa fecha también se leen bien ahora, sin migración.

### Camino A-bis — El cliente pega un link de Google Maps

Pasa **tanto como el pin**: al 06/10/2026 había 5 ubicaciones con 📍 y 5 mensajes de texto
con un `maps.app.goo.gl`. El CRM ahora los resuelve solo.

Ese mensaje llega como `type: "text"` —porque eso es lo que el cliente mandó— pero **con el
mismo objeto `location`** que un pin:

```jsonc
"message": {
  "type": "text",
  "text": "acá vivo https://maps.app.goo.gl/zEqrHNf61oZP2qJ4A",   // lo que escribió
  "location": {
    "lat": -24.1866232,
    "lng": -65.3026021,
    "name": "Gabriel Accesorios, Y4600 San Salvador de Jujuy, Jujuy, Argentina",
    "text": "-24.186623,-65.302602 | Gabriel Accesorios, Y4600 San Salvador de Jujuy…"
  },
  "latitude": -24.1866232,
  "longitude": -65.3026021
}
```

### 🔴 La regla, una sola, para los dos caminos

> **Si el `message` trae `location`, guardar `location.text` en `ubicacion_lead`.**
> Si no lo trae, pedir la dirección por texto (Camino B).

No usar `message.text` para esto. En un pin `text` son las coordenadas, pero en un link es la
frase del cliente con la URL adentro — copiarlo guarda `https://maps.app.goo.gl/…` en el CRM,
que no se puede abrir en un mapa ni le sirve al distribuidor.

Los links de Maps suelen traer **calle y número** en `name`, que el pin nativo no trae.

### Camino B — Escribir la ubicación en el pedido

Para cuando el cliente **dicta** la dirección en lugar de mandar el pin.

```http
PUT /api/v1/leads/attributes/edit/{lead_id}
Authorization: Bearer <token>
Content-Type: application/json

{
  "ubicacion_lead": "-24.193000,-65.297000 | Portón verde, timbre del fondo"
}
```

Un solo campo de texto que lleva las dos cosas, separadas por `|`:

```
<lat>,<lng> | <dirección y referencia>
```

Respuesta verificada: `HTTP 200`. El CRM lo parsea y le muestra al distribuidor:

```
lat = -24.193      lng = -65.297      modo = pin
referencia = "Portón verde, timbre del fondo"
```

#### Reglas del formato

**Las coordenadas necesitan al menos 3 decimales.** `-24.19,-65.29` NO se reconoce;
`-24.193,-65.297` sí. Con menos decimales el pin cae a más de cien metros y no sirve para
tocar un timbre.

**Las coordenadas tienen que estar dentro de Jujuy.** El CRM valida contra la zona de
reparto (lat entre `-25.0` y `-21.5`, lng entre `-67.5` y `-62.0`) y descarta lo que caiga
afuera. Es a propósito: evita guardar un punto inventado que después nadie cuestiona.

**Si no hay coordenadas, mandá sólo el texto.** Es válido y útil:
```json
{ "ubicacion_lead": "Av. San Martín 1234, portón verde al lado del kiosco" }
```
El distribuidor abre una búsqueda en el mapa en vez de un pin exacto.

**La referencia es lo que más importa.** El que toca el timbre usa "portón verde y hay un
árbol afuera" más que un pin a veinte metros. Incluir siempre lo que el cliente diga sobre
cómo encontrar la casa: color del portón, piso, timbre, entre qué calles, puntos de
referencia.

---

## 3. 🔴 Lo que el agente tiene que DEJAR de hacer

Hoy el agente escribe la ubicación dentro del `description` del pedido, junto con todo lo
demás:

```
Cliente: Danitza | Ciudad: San Salvador de Jujuy | Ubicación: 1091, casa en esquina |
Pedido: 2 x Botellón de agua purificada 20 L, 1 x Bomba Eléctrica para Bidón |
Total: Bs 30000.00 | 2× Botellón… | Envío a domicilio | zona: San Salvador de Jujuy |
¡Genial! 🙌 Tu pedido ha sido confirmado. 💧 El pago se coordinará…
```

**El CRM le muestra ese bloque ENTERO al distribuidor como "referencia"**, porque cuando
`ubicacion_lead` está vacío usa el `description` como respaldo. El distribuidor recibe un
muro de texto con el resumen de la conversación en lugar de "casa en esquina".

Lo que hay que cambiar:

- ✅ **Escribir la ubicación en `ubicacion_lead`**, con el formato de arriba.
- ❌ **No** poner `Ubicación: …` dentro del `description`.
- El `description` puede seguir teniendo el resumen del pedido; lo que no tiene que estar
  ahí es la ubicación.

Dos detalles más que se ven en ese ejemplo y conviene corregir:

- Dice **`Bs 30000.00`**. La moneda de Sensia es el **peso argentino**, no el boliviano
  —eso es herencia de otra instalación—.
- Dice **`Total: Bs 30000.00`** para 2 botellones + 1 bomba. Con los precios reales de la
  zona de San Salvador de Jujuy ($8.000 cada botellón) el total no coincide. Conviene que
  el agente calcule el total con el precio del producto **de la zona del cliente**, que es
  justamente lo que la Regla 2 habilita.

---

## 4. Qué NO se puede hacer todavía

**El agente no puede escribir el domicilio permanente del cliente.** La ubicación que
guarda por `ubicacion_lead` queda en **el pedido**, no en la ficha del cliente.

El domicilio del cliente —el que se reusa en todos sus pedidos— vive en una tabla propia y
sólo se escribe desde el Punto (el distribuidor lo marca en el mapa o con el GPS en la
puerta) o automáticamente desde el pin de WhatsApp. No hay endpoint de API para eso.

En la práctica alcanza, porque:
- El pin de WhatsApp **sí** se guarda en el cliente, automáticamente.
- Lo que el agente escribe en `ubicacion_lead` sirve para ese pedido, que es lo que el
  distribuidor necesita para entregarlo.

---

## Resumen para configurar el agente

1. Al armar un pedido, pedir productos con `GET /api/v1/products` y **filtrar por
   `ciudad_producto_sucursal`** según la zona del cliente.
2. **Descartar** los productos con `products: false`.
3. Calcular el total con el `price` del producto de **esa** zona.
4. Pedirle al cliente que **comparta su ubicación por WhatsApp**. Se guarda solo.
5. Si la dicta, escribirla en `ubicacion_lead` con
   `PUT /api/v1/leads/attributes/edit/{lead_id}`, formato
   `"<lat>,<lng> | <referencia>"` (3+ decimales, dentro de Jujuy).
6. **No** poner la ubicación en el `description`.
7. Usar **$** (pesos argentinos), no `Bs`.
